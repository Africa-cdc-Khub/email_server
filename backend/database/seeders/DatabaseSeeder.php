<?php

namespace Database\Seeders;

use App\Enums\EmailDriver;
use App\Models\BrandingSetting;
use App\Models\EmailProvider;
use App\Models\ExternalIntegration;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        BrandingSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'app_name' => env('BRANDING_APP_NAME', 'Africa CDC Email Server'),
                'tagline' => env('BRANDING_TAGLINE', 'Staff portal email gateway'),
                'logo_path' => 'branding/logo.png',
                'logo_dark_path' => 'branding/logo-dark.png',
                'primary_color' => env('BRANDING_PRIMARY_COLOR') ?: '#0d7a3a',
                'secondary_color' => env('BRANDING_SECONDARY_COLOR') ?: '#c9a227',
                'support_email' => env('MAIL_FROM_ADDRESS') ?: 'notifications@africacdc.org',
            ],
        );

        $exchange = EmailProvider::query()->updateOrCreate(
            ['slug' => 'default-exchange'],
            [
                'name' => 'Default Exchange',
                'driver' => EmailDriver::Exchange,
                'config' => [
                    'tenant_id' => env('EXCHANGE_TENANT_ID', ''),
                    'client_id' => env('EXCHANGE_CLIENT_ID', ''),
                    'client_secret' => env('EXCHANGE_CLIENT_SECRET', ''),
                    'redirect_uri' => env('EXCHANGE_REDIRECT_URI', ''),
                    'scope' => env('EXCHANGE_SCOPE', 'https://graph.microsoft.com/Mail.Send'),
                    'auth_method' => env('EXCHANGE_AUTH_METHOD', 'client_credentials'),
                ],
                'from_address' => env('MAIL_FROM_ADDRESS', 'notifications@africacdc.org'),
                'from_name' => env('MAIL_FROM_NAME', 'Africa CDC Mailer'),
                'is_default' => true,
                'is_active' => true,
                'priority' => 10,
                'description' => 'Microsoft Graph API — same pattern as Staff APM / Helpdesk.',
            ],
        );

        EmailProvider::query()->updateOrCreate(
            ['slug' => 'fallback-smtp'],
            [
                'name' => 'SMTP Fallback',
                'driver' => EmailDriver::Smtp,
                'config' => [
                    'host' => env('MAIL_HOST', 'smtp.office365.com'),
                    'port' => (int) env('MAIL_PORT', 587),
                    'encryption' => env('MAIL_ENCRYPTION', 'tls'),
                    'username' => env('MAIL_USERNAME', ''),
                    'password' => env('MAIL_PASSWORD', ''),
                ],
                'from_address' => env('MAIL_FROM_ADDRESS', 'notifications@africacdc.org'),
                'from_name' => env('MAIL_FROM_NAME', 'Africa CDC Mailer'),
                'is_default' => false,
                'is_active' => false,
                'priority' => 100,
                'description' => 'SMTP provider — Office 365 / generic SMTP.',
            ],
        );

        EmailProvider::query()->updateOrCreate(
            ['slug' => 'dev-log'],
            [
                'name' => 'Development Log',
                'driver' => EmailDriver::Log,
                'config' => [],
                'from_address' => 'dev@localhost',
                'from_name' => 'Email Server Dev',
                'is_default' => false,
                'is_active' => true,
                'priority' => 999,
                'description' => 'Writes emails to Laravel log — for local testing.',
            ],
        );

        $clientSecret = env('INTEGRATION_CLIENT_SECRET', '@#nr7KvdUU7b#T_N#mGCNw!hM#!eZ_su');

        ExternalIntegration::query()->updateOrCreate(
            ['slug' => 'staff-portal'],
            [
                'name' => 'Staff Portal',
                'api_key_hash' => ExternalIntegration::hashClientSecret($clientSecret),
                'api_key_prefix' => ExternalIntegration::clientSecretHint($clientSecret),
                'email_provider_id' => $exchange->id,
                'allowed_ips' => [],
                'settings' => ['source' => 'seed'],
                'is_active' => true,
                'description' => 'Example integration for external systems (APM, Helpdesk, etc.).',
            ],
        );

        $adminEmail = env('ADMIN_EMAIL', 'andrewa@africacdc.org');
        $adminPassword = env('ADMIN_PASSWORD', 'Madmirt2417');

        $admin = User::query()->firstOrCreate(
            ['email' => $adminEmail],
            [
                'name' => env('ADMIN_NAME', 'Super Admin'),
                'password' => Hash::make($adminPassword),
                'is_admin' => true,
                'is_active' => true,
            ],
        );

        if (! $admin->wasRecentlyCreated) {
            $admin->forceFill([
                'name' => env('ADMIN_NAME', 'Super Admin'),
                'is_admin' => true,
                'is_active' => true,
            ])->save();

            if (filter_var(env('ADMIN_RESET_PASSWORD', false), FILTER_VALIDATE_BOOL)) {
                $admin->forceFill(['password' => Hash::make($adminPassword)])->save();
            }
        }

        User::query()
            ->where('is_admin', true)
            ->where('email', '!=', $adminEmail)
            ->update(['is_active' => false]);

        if ($this->command) {
            $this->command->warn('Integration credentials — client_id: staff-portal | client_secret: '.$clientSecret);
        }
    }
}
