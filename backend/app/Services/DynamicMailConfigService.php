<?php

namespace App\Services;

use App\Enums\EmailDriver;
use App\Models\EmailLog;
use App\Models\EmailProvider;
use App\Models\ExternalIntegration;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class DynamicMailConfigService
{
    public function __construct(
        private readonly ExchangeConfigurationResolver $exchangeResolver,
    ) {}

    public function defaultProvider(): ?EmailProvider
    {
        return EmailProvider::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->orderBy('priority')
            ->first()
            ?? EmailProvider::query()
                ->where('is_active', true)
                ->orderBy('priority')
                ->first();
    }

    /**
     * Active providers to try after the primary fails, ordered by priority.
     *
     * @return list<EmailProvider>
     */
    public function fallbackProviders(?int $excludeProviderId = null): array
    {
        return EmailProvider::query()
            ->where('is_active', true)
            ->when(
                $excludeProviderId !== null,
                fn ($q) => $q->where('id', '!=', $excludeProviderId)
            )
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function resolveProvider(?int $providerId = null): EmailProvider
    {
        if ($providerId !== null) {
            $provider = EmailProvider::query()
                ->where('is_active', true)
                ->find($providerId);

            if ($provider !== null) {
                return $provider;
            }

            // Sticky integration/log pins must not keep using a disabled provider
            // after the fleet default was switched (e.g. Exchange → SMTP).
            $fallback = $this->defaultProvider();
            if ($fallback !== null) {
                return $fallback;
            }

            throw new RuntimeException('Email provider not found or inactive.');
        }

        $provider = $this->defaultProvider();

        if ($provider === null) {
            throw new RuntimeException('No active email provider configured.');
        }

        return $provider;
    }

    /**
     * Promote a provider to fleet default and make all integrations + pending
     * mail follow it (clears sticky per-integration provider pins).
     *
     * @return array{integrations_updated: int, pending_logs_updated: int}
     */
    public function adoptAsFleetDefault(EmailProvider $provider): array
    {
        EmailProvider::query()->where('id', '!=', $provider->id)->update(['is_default' => false]);
        $provider->update(['is_default' => true, 'is_active' => true]);

        return $this->rebindFleetToProvider($provider->fresh() ?? $provider);
    }

    /**
     * When a provider is disabled, move sticky integrations and pending logs
     * off it onto the current default.
     *
     * @return array{integrations_updated: int, pending_logs_updated: int}
     */
    public function rebindAwayFromProvider(EmailProvider $retired): array
    {
        $default = $this->defaultProvider();
        if ($default === null || $default->id === $retired->id) {
            // Clear pins even if no other default exists yet — sends will error clearly.
            $integrationsUpdated = ExternalIntegration::query()
                ->where('email_provider_id', $retired->id)
                ->update([
                    'email_provider_id' => null,
                    'provider_mailbox_id' => null,
                ]);

            return [
                'integrations_updated' => $integrationsUpdated,
                'pending_logs_updated' => 0,
            ];
        }

        $integrationsUpdated = ExternalIntegration::query()
            ->where('email_provider_id', $retired->id)
            ->update([
                'email_provider_id' => null,
                'provider_mailbox_id' => null,
            ]);

        $pendingLogsUpdated = $this->rebindPendingLogsToProvider($default, $retired->id);

        return [
            'integrations_updated' => $integrationsUpdated,
            'pending_logs_updated' => $pendingLogsUpdated,
        ];
    }

    /**
     * @return array{integrations_updated: int, pending_logs_updated: int}
     */
    public function rebindFleetToProvider(EmailProvider $provider): array
    {
        // Null pin = follow system default on every future default switch.
        $integrationsUpdated = ExternalIntegration::query()->update([
            'email_provider_id' => null,
            'provider_mailbox_id' => null,
        ]);

        $pendingLogsUpdated = $this->rebindPendingLogsToProvider($provider);

        $this->purgeExchangeClient();

        return [
            'integrations_updated' => $integrationsUpdated,
            'pending_logs_updated' => $pendingLogsUpdated,
        ];
    }

    /**
     * @return int Number of pending logs updated
     */
    private function rebindPendingLogsToProvider(EmailProvider $provider, ?int $onlyFromProviderId = null): int
    {
        $query = EmailLog::query()->where('status', 'pending');

        if ($onlyFromProviderId !== null) {
            $query->where('email_provider_id', $onlyFromProviderId);
        } else {
            $query->where(function ($q) use ($provider) {
                $q->whereNull('email_provider_id')
                    ->orWhere('email_provider_id', '!=', $provider->id);
            });
        }

        $updated = 0;
        $query->orderBy('id')->chunkById(100, function ($logs) use ($provider, &$updated) {
            foreach ($logs as $log) {
                $meta = $log->meta ?? [];
                unset($meta['from_mailbox_id'], $meta['send_via_provider_id']);
                $meta['rebinding_from_provider_id'] = $log->email_provider_id;
                $meta['rebound_to_provider_id'] = $provider->id;
                $meta['rebound_at'] = now()->toIso8601String();

                $log->update([
                    'email_provider_id' => $provider->id,
                    'driver' => $provider->driver->value,
                    'from_address' => null,
                    'meta' => $meta,
                ]);
                $updated++;
            }
        });

        return $updated;
    }

    /**
     * @return array{address: string|null, name: string}
     */
    public function resolveFromIdentity(EmailProvider $provider): array
    {
        return [
            'address' => $this->exchangeResolver->resolveFromAddress($provider),
            'name' => $this->exchangeResolver->resolveFromName($provider),
        ];
    }

    public function applyProvider(EmailProvider $provider): string
    {
        $mailerName = 'provider_'.$provider->id;
        $from = $this->resolveFromIdentity($provider);

        $this->registerMailer($provider, $mailerName);

        Config::set('mail.from.address', $from['address']);
        Config::set('mail.from.name', $from['name']);
        Config::set('mail.default', $mailerName);

        if ($provider->driver === EmailDriver::Exchange) {
            Config::set('exchange-email', $this->exchangeResolver->resolve($provider));
        }

        return $mailerName;
    }

    private function registerMailer(EmailProvider $provider, string $mailerName): void
    {
        $mailers = Config::get('mail.mailers', []);

        $mailers[$mailerName] = match ($provider->driver) {
            EmailDriver::Exchange => ['transport' => 'exchange'],
            EmailDriver::Smtp => [
                'transport' => 'smtp',
                'host' => $provider->configValue('host') ?: null,
                'port' => (int) ($provider->configValue('port') ?: 587),
                'encryption' => $provider->configValue('encryption') ?: null,
                'username' => $provider->configValue('username') ?: null,
                'password' => $provider->configValue('password') ?: null,
                'timeout' => null,
                'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST),
            ],
            EmailDriver::Ses => ['transport' => 'ses'],
            EmailDriver::Log => [
                'transport' => 'log',
                'channel' => $provider->configValue('channel'),
            ],
        };

        Config::set('mail.mailers', $mailers);
    }

    /**
     * Drop process-local Exchange/mailer caches so the next send rebuilds from
     * the current provider config (critical for long-lived queue:work workers).
     */
    public function purgeExchangeClient(): void
    {
        app()->forgetInstance(ExchangeGraphMailClient::class);

        // MailManager caches mailer instances; forgetting only the Graph client
        // leaves ExchangeGraphTransport holding the previous client/secret.
        Mail::forgetMailers();
    }
}
