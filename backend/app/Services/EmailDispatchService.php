<?php

namespace App\Services;

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
    ): EmailLog {
        $provider = $this->mailConfig->resolveProvider($providerId);

        $meta = $source !== null ? ['source' => $source] : null;

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

    public function testProvider(EmailProvider $provider, string $to): EmailLog
    {
        $subject = 'Email Server test — '.$provider->name.' — '.now()->toDateTimeString();
        $body = '<p>This is a test email from the <strong>Email Server</strong> admin panel.</p>'
            .'<p>Provider: <code>'.e($provider->name).'</code> ('.e($provider->driver->value).')</p>';

        return $this->send($to, $subject, $body, true, $provider->id, null, [], [], 'admin_test');
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
        $mailer = $this->mailConfig->applyProvider($provider);

        try {
            Mail::mailer($mailer)->send([], [], function (Message $message) use ($to, $subject, $body, $isHtml, $provider, $cc, $bcc, $fromName) {
                $from = $this->mailConfig->resolveFromIdentity($provider);

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
