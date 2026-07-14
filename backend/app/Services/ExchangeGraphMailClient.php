<?php

namespace App\Services;

use AgabaandreOffice365\ExchangeEmailService\ExchangeOAuth;
use RuntimeException;

class ExchangeGraphMailClient
{
    private ?ExchangeOAuth $oauth = null;

    public function __construct(
        private readonly ExchangeConfigurationResolver $exchangeResolver,
    ) {}

    /**
     * @param  string|array<int, string>  $to
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
     */
    public function send(
        string|array $to,
        string $subject,
        string $htmlBody,
        ?string $fromEmail = null,
        ?string $fromName = null,
        array $cc = [],
        array $bcc = [],
    ): void {
        $config = config('exchange-email', []);
        $oauth = $this->oauth();

        if (! $this->exchangeResolver->isConfigured()) {
            throw new RuntimeException(
                'Exchange OAuth is not configured. Set EXCHANGE_TENANT_ID, EXCHANGE_CLIENT_ID, and EXCHANGE_CLIENT_SECRET in the environment, or on the email provider.'
            );
        }

        if ($oauth->getAuthMethod() === ExchangeOAuth::AUTH_CLIENT_CREDENTIALS) {
            $oauth->getClientCredentialsToken();
        } elseif (! $oauth->hasValidToken()) {
            $oauth->refreshAccessToken();
        }

        $fromEmail = (string) ($fromEmail
            ?: ($config['from_email'] ?? null)
            ?: config('mail.from.address'));
        $fromName = (string) ($fromName
            ?: ($config['from_name'] ?? null)
            ?: config('mail.from.name', ExchangeConfigurationResolver::DEFAULT_FROM_NAME));

        if ($fromEmail === '') {
            throw new RuntimeException('No from email address configured for Exchange mail sending.');
        }

        $ok = $oauth->sendEmail(
            $to,
            $subject,
            $htmlBody,
            true,
            $fromEmail,
            $fromName,
            $cc,
            $bcc,
        );

        if (! $ok) {
            throw new RuntimeException(
                'Microsoft Graph mail send failed: '.($oauth->lastSendError ?? 'unknown error')
            );
        }
    }

    private function oauth(): ExchangeOAuth
    {
        if ($this->oauth !== null) {
            return $this->oauth;
        }

        $config = config('exchange-email', []);

        $this->oauth = new ExchangeOAuth(
            $config['tenant_id'] ?? null,
            $config['client_id'] ?? null,
            $config['client_secret'] ?? null,
            $config['redirect_uri'] ?? null,
            $config['scope'] ?? 'https://graph.microsoft.com/.default',
            $config['auth_method'] ?? ExchangeOAuth::AUTH_CLIENT_CREDENTIALS,
            $config['from_email'] ?? null,
            $config['from_name'] ?? ExchangeConfigurationResolver::DEFAULT_FROM_NAME,
        );

        return $this->oauth;
    }
}
