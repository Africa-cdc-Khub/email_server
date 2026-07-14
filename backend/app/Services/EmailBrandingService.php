<?php

namespace App\Services;

use App\Models\BrandingSetting;
use App\Models\ExternalIntegration;

class EmailBrandingService
{
    public function appName(): string
    {
        $branding = BrandingSetting::current();

        return $branding->app_name ?: (string) config('app.name', 'Email Server');
    }

    public function resolveFromName(?ExternalIntegration $integration = null, ?string $providerFromName = null): string
    {
        if ($integration !== null) {
            return $integration->name;
        }

        return $providerFromName ?: $this->appName();
    }

    public function wrapHtml(string $body, ?ExternalIntegration $integration = null): string
    {
        if (str_contains($body, 'data-email-server-branded')) {
            return $body;
        }

        return <<<HTML
<div data-email-server-branded="1" style="font-family:Inter,Arial,sans-serif;color:#333;line-height:1.6;">
  {$body}
</div>
HTML;
    }

    public function wrapPlainText(string $body, ?ExternalIntegration $integration = null): string
    {
        return $body;
    }
}
