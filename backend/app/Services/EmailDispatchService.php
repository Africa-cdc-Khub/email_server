<?php

namespace App\Services;

use App\Enums\EmailDriver;
use App\Exceptions\MailboxQuotaExhaustedException;
use App\Exceptions\PermanentEmailDeliveryException;
use App\Jobs\SendEmailJob;
use App\Models\EmailLog;
use App\Models\EmailProvider;
use App\Models\ExternalIntegration;
use App\Support\EmailPriority;
use App\Support\MailHeaderSanitizer;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class EmailDispatchService
{
    public function __construct(
        private readonly DynamicMailConfigService $mailConfig,
        private readonly EmailBrandingService $branding,
        private readonly PhpMailerSmtpMailer $phpMailerSmtp,
        private readonly EmailAttachmentService $attachments,
        private readonly MailboxSelector $mailboxSelector,
    ) {}

    /**
     * Queue an email for async delivery (non-blocking HTTP response).
     *
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
     * @param  list<array{filename: string, content: string, content_type: string, size: int}>  $attachmentPayload
     */
    public function queue(
        string $to,
        string $subject,
        string $body,
        bool $isHtml = true,
        ?int $providerId = null,
        ?ExternalIntegration $integration = null,
        array $cc = [],
        array $bcc = [],
        ?string $source = null,
        ?string $senderIp = null,
        array $attachmentPayload = [],
        ?\DateTimeInterface $sendAt = null,
    ): EmailLog {
        $provider = $this->mailConfig->resolveProvider($providerId);

        $meta = [
            'body' => $body,
            'is_html' => $isHtml,
            'cc' => $cc,
            'bcc' => $bcc,
        ];

        if ($source !== null) {
            $meta['source'] = $source;
        }

        if ($senderIp !== null && $senderIp !== '') {
            $meta['sender_ip'] = $senderIp;
        }

        if ($sendAt !== null) {
            $meta['send_at'] = $sendAt->format(\DateTimeInterface::ATOM);
        }

        $log = EmailLog::query()->create([
            'email_provider_id' => $provider->id,
            'external_integration_id' => $integration?->id,
            'to' => $to,
            'subject' => MailHeaderSanitizer::line($subject, 500),
            'status' => 'pending',
            'driver' => $provider->driver->value,
            'meta' => $meta,
        ]);

        if ($attachmentPayload !== []) {
            $stored = $this->attachments->store($attachmentPayload, $log->id);
            $meta['attachments'] = $stored;
            $meta['attachment_count'] = count($stored);
            $log->update(['meta' => $meta]);
        }

        $this->dispatchSendJob($log, $sendAt);

        return $log->fresh() ?? $log;
    }

    /**
     * Deliver a queued email (runs inside queue worker).
     */
    public function deliver(int $emailLogId): EmailLog
    {
        $lock = Cache::lock('email-deliver:'.$emailLogId, 180);

        if (! $lock->get()) {
            return EmailLog::query()->findOrFail($emailLogId);
        }

        try {
            $log = EmailLog::query()->findOrFail($emailLogId);

            // Only pending logs are sent. Failed/cleared/paused rows must not send
            // even if a SendEmailJob was already sitting on the queue.
            if ($log->status !== 'pending') {
                return $log;
            }

            $meta = $log->meta ?? [];

            $body = (string) ($meta['body'] ?? '');
            $isHtml = (bool) ($meta['is_html'] ?? true);
            $cc = $meta['cc'] ?? [];
            $bcc = $meta['bcc'] ?? [];
            $attachmentMeta = is_array($meta['attachments'] ?? null) ? $meta['attachments'] : [];

            if ($body === '') {
                $log->update([
                    'status' => 'failed',
                    'error_message' => 'Missing email body in queue payload.',
                ]);

                throw new PermanentEmailDeliveryException('Missing email body in queue payload.');
            }

            $log->loadMissing('externalIntegration');

            if ($isHtml) {
                $body = $this->branding->wrapHtml($body, $log->externalIntegration);
            } else {
                $body = $this->branding->wrapPlainText($body, $log->externalIntegration);
            }

            $loadedAttachments = $attachmentMeta === []
                ? []
                : $this->attachments->load($attachmentMeta);

            return $this->transmit(
                log: $log,
                to: $log->to,
                subject: $log->subject,
                body: $body,
                isHtml: $isHtml,
                providerId: $log->email_provider_id,
                cc: is_array($cc) ? $cc : [],
                bcc: is_array($bcc) ? $bcc : [],
                fromName: $this->branding->resolveFromName(
                    $log->externalIntegration,
                    $this->mailConfig->resolveFromIdentity(
                        $this->mailConfig->resolveProvider($log->email_provider_id),
                    )['name'],
                ),
                markFailedOnError: false,
                attachmentPayload: $loadedAttachments,
                cleanupAttachmentMeta: $attachmentMeta,
                allowFallback: true,
                explicitMailboxId: null,
                preferredMailboxId: $log->externalIntegration?->provider_mailbox_id,
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * Send immediately (admin tests / sync queue).
     *
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
     * @param  list<array{filename: string, content: string, content_type: string, size: int}>  $attachmentPayload
     */
    public function send(
        string $to,
        string $subject,
        string $body,
        bool $isHtml = true,
        ?int $providerId = null,
        ?ExternalIntegration $integration = null,
        array $cc = [],
        array $bcc = [],
        ?string $source = null,
        ?string $senderIp = null,
        array $attachmentPayload = [],
        ?int $explicitMailboxId = null,
    ): EmailLog {
        $provider = $this->mailConfig->resolveProvider($providerId);

        $meta = [
            'body' => $body,
            'is_html' => $isHtml,
            'cc' => $cc,
            'bcc' => $bcc,
        ];

        if ($source !== null) {
            $meta['source'] = $source;
        }

        if ($senderIp !== null && $senderIp !== '') {
            $meta['sender_ip'] = $senderIp;
        }

        $log = EmailLog::query()->create([
            'email_provider_id' => $provider->id,
            'external_integration_id' => $integration?->id,
            'to' => $to,
            'subject' => MailHeaderSanitizer::line($subject, 500),
            'status' => 'pending',
            'driver' => $provider->driver->value,
            'meta' => $meta,
        ]);

        $stored = [];
        if ($attachmentPayload !== []) {
            $stored = $this->attachments->store($attachmentPayload, $log->id);
            $meta['attachments'] = $stored;
            $meta['attachment_count'] = count($stored);
            $log->update(['meta' => $meta]);
        }

        return $this->transmit(
            log: $log,
            to: $to,
            subject: $subject,
            body: $isHtml
                ? $this->branding->wrapHtml($body, $integration)
                : $this->branding->wrapPlainText($body, $integration),
            isHtml: $isHtml,
            providerId: $provider->id,
            cc: $cc,
            bcc: $bcc,
            fromName: $this->branding->resolveFromName(
                $integration,
                $this->mailConfig->resolveFromIdentity($provider)['name'],
            ),
            attachmentPayload: $attachmentPayload,
            cleanupAttachmentMeta: $stored,
            allowFallback: ($source ?? '') !== 'admin_test',
            explicitMailboxId: $explicitMailboxId,
            preferredMailboxId: $explicitMailboxId === null
                ? $integration?->provider_mailbox_id
                : null,
        );
    }

    /**
     * Re-queue a failed (or pending) email that still has its body stored in meta.
     */
    public function retry(EmailLog $log): EmailLog
    {
        if ($log->status === 'sent') {
            throw new RuntimeException('This email was already sent successfully.');
        }

        $meta = $log->meta ?? [];
        $body = (string) ($meta['body'] ?? '');

        if ($body === '') {
            throw new RuntimeException(
                'This email cannot be resent because its body was not stored. Only queued deliveries can be retried.'
            );
        }

        $meta['paused_by_admin'] = false;
        unset($meta['paused_at'], $meta['pause_reason']);

        $log->update([
            'status' => 'pending',
            'error_message' => null,
            'meta' => $meta,
        ]);

        $this->dispatchSendJob($log->fresh() ?? $log);

        return $log->fresh() ?? $log;
    }

    /**
     * Count every pending email (admin + integrations) still waiting to send.
     */
    public function countAllPending(): int
    {
        return EmailLog::query()->where('status', 'pending')->count();
    }

    /**
     * Fail and clear every pending email so workers stop sending immediately.
     * Bodies stay stored for audit; these are not auto-retried by subject retry.
     *
     * @return array{cleared: int}
     */
    public function failAndClearAllPending(string $reason = 'Cleared by admin'): array
    {
        $cleared = 0;
        $reason = trim($reason) !== '' ? trim($reason) : 'Cleared by admin';

        EmailLog::query()
            ->where('status', 'pending')
            ->orderBy('id')
            ->chunkById(100, function ($logs) use (&$cleared, $reason) {
                foreach ($logs as $log) {
                    $meta = $log->meta ?? [];
                    $meta['cleared_by_admin'] = true;
                    $meta['paused_by_admin'] = true;
                    $meta['cleared_at'] = now()->toIso8601String();
                    $meta['pause_reason'] = $reason;
                    unset($meta['send_at']);

                    $log->update([
                        'status' => 'failed',
                        'error_message' => $reason.' — queue cleared by admin.',
                        'meta' => $meta,
                    ]);
                    $cleared++;
                }
            });

        return ['cleared' => $cleared];
    }

    /**
     * Count pending (not yet sent) logs for an exact subject.
     */
    public function countPendingBySubject(string $subject): int
    {
        return $this->pendingBySubjectQuery($subject)->count();
    }

    /**
     * Count admin-paused failed logs for an exact subject (retryable).
     */
    public function countPausedBySubject(string $subject): int
    {
        return $this->pausedBySubjectQuery($subject)->count();
    }

    /**
     * Mark pending emails with this exact subject as failed so workers skip them.
     * Bodies stay stored so they can be retried / scheduled later.
     *
     * @return array{paused: int}
     */
    public function pausePendingBySubject(string $subject, string $reason = 'Paused by admin (busy hours)'): array
    {
        $subject = trim($subject);
        if ($subject === '') {
            throw new RuntimeException('A subject is required.');
        }

        $paused = 0;
        $reason = trim($reason) !== '' ? trim($reason) : 'Paused by admin (busy hours)';

        $this->pendingBySubjectQuery($subject)->orderBy('id')->chunkById(100, function ($logs) use (&$paused, $reason) {
            foreach ($logs as $log) {
                $meta = $log->meta ?? [];
                $meta['paused_by_admin'] = true;
                $meta['paused_at'] = now()->toIso8601String();
                $meta['pause_reason'] = $reason;

                $log->update([
                    'status' => 'failed',
                    'error_message' => $reason.' — retry later from Queue control.',
                    'meta' => $meta,
                ]);
                $paused++;
            }
        });

        return ['paused' => $paused];
    }

    /**
     * Re-queue admin-paused emails for this subject, optionally delayed until $sendAt.
     *
     * @return array{queued: int, skipped: int}
     */
    public function scheduleRetryPausedBySubject(string $subject, ?\DateTimeInterface $sendAt = null): array
    {
        $subject = trim($subject);
        if ($subject === '') {
            throw new RuntimeException('A subject is required.');
        }

        $queued = 0;
        $skipped = 0;

        $this->pausedBySubjectQuery($subject)->orderBy('id')->chunkById(100, function ($logs) use (&$queued, &$skipped, $sendAt) {
            foreach ($logs as $log) {
                try {
                    $meta = $log->meta ?? [];
                    $body = (string) ($meta['body'] ?? '');
                    if ($body === '') {
                        $skipped++;
                        continue;
                    }

                    $meta['paused_by_admin'] = false;
                    unset($meta['paused_at'], $meta['pause_reason']);
                    if ($sendAt !== null) {
                        $meta['send_at'] = $sendAt->format(\DateTimeInterface::ATOM);
                    } else {
                        unset($meta['send_at']);
                    }

                    $log->update([
                        'status' => 'pending',
                        'error_message' => null,
                        'meta' => $meta,
                    ]);

                    $this->dispatchSendJob($log->fresh() ?? $log, $sendAt);
                    $queued++;
                } catch (RuntimeException) {
                    $skipped++;
                }
            }
        });

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * Distinct pending subjects (admin sends) with counts — for Queue control UI.
     *
     * @return list<array{subject: string, pending_count: int}>
     */
    public function pendingSubjects(int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));

        return EmailLog::query()
            ->selectRaw('subject, COUNT(*) as pending_count')
            ->where('status', 'pending')
            ->where(function ($q) {
                $q->where('meta->source', 'admin')
                    ->orWhereNull('external_integration_id');
            })
            ->whereNotNull('subject')
            ->where('subject', '!=', '')
            ->groupBy('subject')
            ->orderByDesc('pending_count')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'subject' => (string) $row->subject,
                'pending_count' => (int) $row->pending_count,
            ])
            ->values()
            ->all();
    }

    private function pendingBySubjectQuery(string $subject)
    {
        return EmailLog::query()
            ->where('status', 'pending')
            ->where('subject', trim($subject))
            ->where(function ($q) {
                $q->where('meta->source', 'admin')
                    ->orWhereNull('external_integration_id');
            });
    }

    private function pausedBySubjectQuery(string $subject)
    {
        return EmailLog::query()
            ->where('status', 'failed')
            ->where('subject', trim($subject))
            ->where('meta->paused_by_admin', true);
    }

    /**
     * Re-queue all failed emails that still have a stored body.
     * Optional client filter: external_integration_id or "none" for admin/internal.
     *
     * @return array{queued: int, skipped: int}
     */
    public function retryAllFailed(?string $externalIntegrationId = null, int $staleSeconds = 0): array
    {
        return $this->retryAllByStatus('failed', $externalIntegrationId, $staleSeconds);
    }

    /**
     * Re-queue all pending emails that still have a stored body (stuck jobs).
     *
     * @return array{queued: int, skipped: int}
     */
    public function retryAllPending(?string $externalIntegrationId = null, int $staleSeconds = 0): array
    {
        return $this->retryAllByStatus('pending', $externalIntegrationId, $staleSeconds);
    }

    /**
     * @param  'failed'|'pending'  $status
     * @return array{queued: int, skipped: int}
     */
    public function retryAllByStatus(
        string $status,
        ?string $externalIntegrationId = null,
        int $staleSeconds = 0,
    ): array {
        if (! in_array($status, ['failed', 'pending'], true)) {
            throw new RuntimeException('Unsupported email log status for bulk retry.');
        }

        $query = EmailLog::query()
            ->where('status', $status)
            ->orderBy('id');

        if ($staleSeconds > 0) {
            $query->where('updated_at', '<=', now()->subSeconds($staleSeconds));
        }

        if ($externalIntegrationId !== null && $externalIntegrationId !== '') {
            if ($externalIntegrationId === 'none' || $externalIntegrationId === '0') {
                $query->whereNull('external_integration_id');
            } else {
                $query->where('external_integration_id', (int) $externalIntegrationId);
            }
        }

        $queued = 0;
        $skipped = 0;

        $query->chunkById(100, function ($logs) use (&$queued, &$skipped) {
            foreach ($logs as $log) {
                try {
                    $this->retry($log);
                    $queued++;
                } catch (RuntimeException) {
                    $skipped++;
                }
            }
        });

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    private function dispatchSendJob(EmailLog $log, ?\DateTimeInterface $sendAt = null): void
    {
        $log->loadMissing('externalIntegration');
        $queue = EmailPriority::queueFor(
            (string) $log->subject,
            $log->externalIntegration,
        );

        $pending = SendEmailJob::dispatch($log->id, $queue);

        if ($sendAt !== null) {
            $pending->delay($sendAt);
        }
    }

    public function testProvider(
        EmailProvider $provider,
        string $to,
        ?string $senderIp = null,
        ?int $fromMailboxId = null,
    ): EmailLog {
        $subject = 'Email Server test — '.$provider->name.' — '.now()->toDateTimeString();
        $body = '<p>This is a test email from the <strong>Email Server</strong> admin panel.</p>'
            .'<p>Provider: <code>'.e($provider->name).'</code> ('.e($provider->driver->value).')</p>';

        return $this->send(
            to: $to,
            subject: $subject,
            body: $body,
            isHtml: true,
            providerId: $provider->id,
            integration: null,
            cc: [],
            bcc: [],
            source: 'admin_test',
            senderIp: $senderIp,
            explicitMailboxId: $fromMailboxId,
        );
    }

    /**
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
     * @param  list<array{filename: string, content: string, content_type: string, size?: int}>  $attachmentPayload
     * @param  list<array{path?: string}>  $cleanupAttachmentMeta
     */
    private function transmit(
        EmailLog $log,
        string $to,
        string $subject,
        string $body,
        bool $isHtml,
        ?int $providerId,
        array $cc,
        array $bcc,
        ?string $fromName = null,
        bool $markFailedOnError = true,
        array $attachmentPayload = [],
        array $cleanupAttachmentMeta = [],
        bool $allowFallback = true,
        ?int $explicitMailboxId = null,
        ?int $preferredMailboxId = null,
    ): EmailLog {
        $primary = $this->mailConfig->resolveProvider($providerId);
        $subject = MailHeaderSanitizer::line($subject, 500);
        $attemptErrors = [];

        $primaryResult = $this->attemptSendWithProvider(
            log: $log,
            provider: $primary,
            to: $to,
            subject: $subject,
            body: $body,
            isHtml: $isHtml,
            cc: $cc,
            bcc: $bcc,
            fromName: $fromName,
            attachmentPayload: $attachmentPayload,
            cleanupAttachmentMeta: $cleanupAttachmentMeta,
            explicitMailboxId: $explicitMailboxId,
            preferredMailboxId: $preferredMailboxId,
            fallbackMeta: null,
        );

        if ($primaryResult['sent']) {
            return $primaryResult['log'];
        }

        $lastError = $primaryResult['error'] ?? new RuntimeException('Email delivery failed.');
        $attemptErrors[] = $primaryResult['attempt'];

        // Cross-provider fallback (e.g. SMTP) only when the primary's enabled
        // mailboxes are quota-exhausted — not on Graph/SMTP connection errors.
        $mayFallback = $allowFallback && $lastError instanceof MailboxQuotaExhaustedException;

        if ($mayFallback) {
            foreach ($this->mailConfig->fallbackProviders($primary->id) as $fallback) {
                $fallbackResult = $this->attemptSendWithProvider(
                    log: $log,
                    provider: $fallback,
                    to: $to,
                    subject: $subject,
                    body: $body,
                    isHtml: $isHtml,
                    cc: $cc,
                    bcc: $bcc,
                    fromName: $fromName,
                    attachmentPayload: $attachmentPayload,
                    cleanupAttachmentMeta: $cleanupAttachmentMeta,
                    explicitMailboxId: null,
                    preferredMailboxId: null,
                    fallbackMeta: [
                        'fallback_from_provider_id' => $primary->id,
                        'fallback_from_driver' => $primary->driver->value,
                        'fallback_error' => $lastError->getMessage(),
                        'fallback_attempts' => $attemptErrors,
                    ],
                );

                if ($fallbackResult['sent']) {
                    return $fallbackResult['log'];
                }

                $lastError = $fallbackResult['error'] ?? $lastError;
                $attemptErrors[] = $fallbackResult['attempt'];
            }
        }

        if ($markFailedOnError) {
            $meta = $log->meta ?? [];
            if ($attemptErrors !== []) {
                $meta['fallback_attempts'] = $attemptErrors;
            }

            $log->update([
                'status' => 'failed',
                'error_message' => $lastError->getMessage(),
                'meta' => $meta,
            ]);
        }

        throw $lastError;
    }

    /**
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
     * @param  list<array{filename: string, content: string, content_type: string, size?: int}>  $attachmentPayload
     * @param  list<array{path?: string}>  $cleanupAttachmentMeta
     * @param  array<string, mixed>|null  $fallbackMeta
     * @return array{
     *     sent: bool,
     *     log?: EmailLog,
     *     error?: Throwable,
     *     attempt: array{provider_id: int, driver: string, error: string}
     * }
     */
    private function attemptSendWithProvider(
        EmailLog $log,
        EmailProvider $provider,
        string $to,
        string $subject,
        string $body,
        bool $isHtml,
        array $cc,
        array $bcc,
        ?string $fromName,
        array $attachmentPayload,
        array $cleanupAttachmentMeta,
        ?int $explicitMailboxId,
        ?int $preferredMailboxId,
        ?array $fallbackMeta,
    ): array {
        $this->mailConfig->purgeExchangeClient();

        try {
            $log->loadMissing('externalIntegration');
            $mailbox = $this->mailboxSelector->select(
                $provider,
                $explicitMailboxId ?? $preferredMailboxId,
                $log->externalIntegration,
                strictMailbox: $explicitMailboxId !== null,
            );
        } catch (MailboxQuotaExhaustedException|\InvalidArgumentException $e) {
            return [
                'sent' => false,
                'error' => $e,
                'attempt' => [
                    'provider_id' => $provider->id,
                    'driver' => $provider->driver->value,
                    'error' => $e->getMessage(),
                ],
            ];
        }

        $from = $this->mailConfig->resolveFromIdentity($provider);
        $from['address'] = $mailbox->email;
        $resolvedFromName = MailHeaderSanitizer::line(
            (string) ($fromName ?: ($from['name'] ?? '')),
            255
        );

        try {
            $this->sendViaProvider(
                provider: $provider,
                to: $to,
                subject: $subject,
                body: $body,
                isHtml: $isHtml,
                from: $from,
                fromName: $resolvedFromName,
                cc: $cc,
                bcc: $bcc,
                attachmentPayload: $attachmentPayload,
                mailbox: $mailbox,
            );

            $meta = $log->meta ?? [];
            if (is_array($fallbackMeta)) {
                $meta = array_merge($meta, $fallbackMeta);
            }
            $meta['from_address'] = $mailbox->email;
            $meta['from_mailbox_id'] = $mailbox->id;

            $log->update([
                'email_provider_id' => $provider->id,
                'driver' => $provider->driver->value,
                'from_address' => $mailbox->email,
                'status' => 'sent',
                'error_message' => null,
                'meta' => $meta,
            ]);

            if ($cleanupAttachmentMeta !== []) {
                $this->attachments->deleteStored($cleanupAttachmentMeta);
                $meta = $log->meta ?? [];
                unset($meta['attachments']);
                $meta['attachment_count'] = count($cleanupAttachmentMeta);
                $meta['attachments_delivered'] = true;
                $log->update(['meta' => $meta]);
            }

            return [
                'sent' => true,
                'log' => $log->fresh() ?? $log,
                'attempt' => [
                    'provider_id' => $provider->id,
                    'driver' => $provider->driver->value,
                    'error' => '',
                ],
            ];
        } catch (Throwable $e) {
            return [
                'sent' => false,
                'error' => $e,
                'attempt' => [
                    'provider_id' => $provider->id,
                    'driver' => $provider->driver->value,
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * @param  array{address: string|null, name: string}  $from
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
     * @param  list<array{filename: string, content: string, content_type: string, size?: int}>  $attachmentPayload
     */
    private function sendViaProvider(
        EmailProvider $provider,
        string $to,
        string $subject,
        string $body,
        bool $isHtml,
        array $from,
        string $fromName,
        array $cc,
        array $bcc,
        array $attachmentPayload,
        ?\App\Models\ProviderMailbox $mailbox = null,
    ): void {
        if ($provider->driver === EmailDriver::Smtp) {
            $this->phpMailerSmtp->send(
                provider: $provider,
                to: $to,
                subject: $subject,
                body: $body,
                isHtml: $isHtml,
                fromAddress: (string) ($from['address'] ?? ''),
                fromName: $fromName,
                cc: $cc,
                bcc: $bcc,
                attachments: $attachmentPayload,
                mailbox: $mailbox,
            );

            return;
        }

        $mailer = $this->mailConfig->applyProvider($provider);

        Mail::mailer($mailer)->send([], [], function (Message $message) use ($to, $subject, $body, $isHtml, $from, $cc, $bcc, $fromName, $attachmentPayload) {
            $message->to($to)->subject($subject);

            if (! empty($from['address'])) {
                $message->from($from['address'], $fromName ?: $from['name']);
            }

            foreach ($cc as $address) {
                $message->cc($address);
            }

            foreach ($bcc as $address) {
                $message->bcc($address);
            }

            if ($isHtml) {
                $message->html($body);
            } else {
                $message->text($body);
            }

            foreach ($attachmentPayload as $attachment) {
                $message->attachData(
                    $attachment['content'],
                    $attachment['filename'],
                    ['mime' => $attachment['content_type'] ?? 'application/octet-stream'],
                );
            }
        });
    }
}
