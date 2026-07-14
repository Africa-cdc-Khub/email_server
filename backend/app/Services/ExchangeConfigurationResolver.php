<?php

namespace App\Services;

use App\Models\BrandingSetting;
use App\Models\EmailProvider;
use App\Support\ConfigValue;

class ExchangeConfigurationResolver
{
    public const DEFAULT_FROM_NAME = 'Africa CDC Mailer';

    /**
     * @return array<string, mixed>
     */
    public function resolve(?EmailProvider $provider = null): array
    {
        $env = config('exchange-email', []);

        return [
            'tenant_id' => ConfigValue::firstNonEmpty(
                $env['tenant_id'] ?? null,
                $provider?->configValue('tenant_id'),
            ),
            'client_id' => ConfigValue::firstNonEmpty(
                $env['client_id'] ?? null,
                $provider?->configValue('client_id'),
            ),
            'client_secret' => ConfigValue::firstNonEmpty(
                $env['client_secret'] ?? null,
                $provider?->configValue('client_secret'),
            ),
            'redirect_uri' => ConfigValue::firstNonEmpty(
                $env['redirect_uri'] ?? null,
                $provider?->configValue('redirect_uri'),
            ),
            'scope' => ConfigValue::firstNonEmpty(
                $env['scope'] ?? null,
                $provider?->configValue('scope'),
                'https://graph.microsoft.com/.default',
            ),
            'auth_method' => ConfigValue::firstNonEmpty(
                $env['auth_method'] ?? null,
                $provider?->configValue('auth_method'),
                'client_credentials',
            ),
            'from_email' => $this->resolveFromAddress($provider),
            'from_name' => $this->resolveFromName($provider),
        ];
    }

    public function resolveFromAddress(?EmailProvider $provider = null): ?string
    {
        return ConfigValue::firstNonEmpty(
            config('mail.from.address'),
            config('exchange-email.from_email'),
            $provider?->from_address,
        );
    }

    public function resolveFromName(?EmailProvider $provider = null): string
    {
        $brandingName = BrandingSetting::query()->value('app_name');

        return (string) (ConfigValue::firstNonEmpty(
            config('mail.from.name'),
            config('exchange-email.from_name'),
            $provider?->from_name,
            $brandingName,
        ) ?? self::DEFAULT_FROM_NAME);
    }

    public function isConfigured(?EmailProvider $provider = null): bool
    {
        $config = $this->resolve($provider);

        return ! empty($config['tenant_id'])
            && ! empty($config['client_id'])
            && ! empty($config['client_secret']);
    }
}
