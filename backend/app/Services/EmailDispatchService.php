<?php

namespace App\Services;

use App\Enums\EmailDriver;
use App\Exceptions\PermanentEmailDeliveryException;
use App\Jobs\SendEmailJob;
use App\Models\EmailLog;
use App\Models\EmailProvider;
use App\Models\ExternalIntegration;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class EmailDispatchService
{
    public function __construct(
        private readonly DynamicMailConfigService $mailConfig,
        private readonly EmailBrandingService $branding,
        private readonly PhpMailerSmtpMailer $phpMailerSmtp,
    ) {}

    /**
     * Queue an email for async delivery (non-blocking HTTP response).
     *
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
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
            'subject' => $subject,
            'status' => 'pending',
            'driver' => $provider->driver->value,
            'meta' => $meta,
        ]);

        SendEmailJob::dispatch($log->id);

        return $log;
    }

    /**
     * Deliver a queued email (runs inside queue worker).
     */
    public function deliver(int $emailLogId): EmailLog
    {
        $log = EmailLog::query()->findOrFail($emailLogId);

        if ($log->status === 'sent') {
            return $log;
        }

        $meta = $log->meta ?? [];
        $body = (string) ($meta['body'] ?? '');
        $isHtml = (bool) ($meta['is_html'] ?? true);
        $cc = $meta['cc'] ?? [];
        $bcc = $meta['bcc'] ?? [];

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
        );
    }

    /**
     * Send immediately (admin tests / sync queue).
     *
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
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
            'subject' => $subject,
            'status' => 'pending',
            'driver' => $provider->driver->value,
            'meta' => $meta,
        ]);

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

        $log->update([
            'status' => 'pending',
            'error_message' => null,
        ]);

        SendEmailJob::dispatch($log->id);

        return $log->fresh() ?? $log;
    }

    /**
     * Re-queue all failed emails that still have a stored body.
     * Optional client filter: external_integration_id or "none" for admin/internal.
     *
     * @return array{queued: int, skipped: int}
     */
    public function retryAllFailed(?string $externalIntegrationId = null): array
    {
        $query = EmailLog::query()
            ->where('status', 'failed')
            ->orderBy('id');

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

    public function testProvider(EmailProvider $provider, string $to, ?string $senderIp = null): EmailLog
    {
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
        );
    }

    /**
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
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
    ): EmailLog {
        $provider = $this->mailConfig->resolveProvider($providerId);
        $this->mailConfig->purgeExchangeClient();
        $from = $this->mailConfig->resolveFromIdentity($provider);

        try {
            if ($provider->driver === EmailDriver::Smtp) {
                $this->phpMailerSmtp->send(
                    provider: $provider,
                    to: $to,
                    subject: $subject,
                    body: $body,
                    isHtml: $isHtml,
                    fromAddress: (string) ($from['address'] ?? ''),
                    fromName: $fromName ?: (string) ($from['name'] ?? ''),
                    cc: $cc,
                    bcc: $bcc,
                );
            } else {
                $mailer = $this->mailConfig->applyProvider($provider);

                Mail::mailer($mailer)->send([], [], function (Message $message) use ($to, $subject, $body, $isHtml, $from, $cc, $bcc, $fromName) {
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
                });
            }

            $log->update(['status' => 'sent', 'error_message' => null]);
        } catch (Throwable $e) {
            if ($markFailedOnError) {
                $log->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }

            throw $e;
        }

        return $log->fresh();
    }
}
