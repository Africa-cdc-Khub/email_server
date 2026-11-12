<?php

namespace Tests\Feature;

use App\Enums\EmailDriver;
use App\Jobs\SendEmailJob;
use App\Models\EmailLog;
use App\Models\EmailProvider;
use App\Models\ExchangeOauthToken;
use App\Models\User;
use App\Services\DynamicMailConfigService;
use App\Services\EmailDispatchService;
use App\Services\ExchangeGraphMailClient;
use App\Services\ExchangeOauthTokenStore;
use App\Services\PhpMailerSmtpMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use ReflectionObject;
use Tests\TestCase;

class ProviderCredentialRotationTest extends TestCase
{
    use RefreshDatabase;

    public function test_purge_exchange_client_clears_cached_mailers(): void
    {
        $provider = EmailProvider::query()->create([
            'name' => 'Exchange',
            'slug' => 'exchange-cache',
            'driver' => EmailDriver::Exchange,
            'config' => [
                'tenant_id' => 'tenant-a',
                'client_id' => 'client-a',
                'client_secret' => 'secret-old',
                'auth_method' => 'client_credentials',
            ],
            'from_address' => 'noreply@example.com',
            'is_default' => true,
            'is_active' => true,
        ]);

        $mailConfig = app(DynamicMailConfigService::class);
        $mailerName = $mailConfig->applyProvider($provider);

        // Warm the MailManager + singleton client the way a long-lived queue worker would.
        Mail::mailer($mailerName);
        $before = app(ExchangeGraphMailClient::class);

        $manager = Mail::getFacadeRoot();
        $ref = new ReflectionObject($manager);
        $prop = $ref->getProperty('mailers');
        $prop->setAccessible(true);
        $this->assertArrayHasKey($mailerName, $prop->getValue($manager));

        $mailConfig->purgeExchangeClient();

        $this->assertSame([], $prop->getValue($manager));
        $after = app(ExchangeGraphMailClient::class);
        $this->assertNotSame($before, $after);
    }

    public function test_queued_delivery_uses_smtp_password_updated_after_queue(): void
    {
        $provider = EmailProvider::query()->create([
            'name' => 'SMTP',
            'slug' => 'smtp-rotate',
            'driver' => EmailDriver::Smtp,
            'config' => [
                'host' => 'smtp.example.com',
                'port' => 587,
                'encryption' => 'tls',
                'username' => 'mailer',
                'password' => 'old-password',
            ],
            'from_address' => 'noreply@example.com',
            'from_name' => 'Mailer',
            'is_default' => true,
            'is_active' => true,
        ]);

        $log = EmailLog::query()->create([
            'email_provider_id' => $provider->id,
            'to' => 'recipient@example.com',
            'subject' => 'Queued before rotation',
            'status' => 'pending',
            'driver' => EmailDriver::Smtp->value,
            'meta' => [
                'body' => '<p>Hello</p>',
                'is_html' => true,
                'cc' => [],
                'bcc' => [],
                'source' => 'admin',
            ],
        ]);

        $provider->setConfigSafely(array_merge($provider->safeConfig(), [
            'password' => 'new-password',
        ]));
        $provider->save();

        $smtpMock = Mockery::mock(PhpMailerSmtpMailer::class);
        $smtpMock->shouldReceive('send')
            ->once()
            ->withArgs(function (EmailProvider $p) {
                return ($p->safeConfig()['password'] ?? null) === 'new-password';
            })
            ->andReturnNull();
        $this->app->instance(PhpMailerSmtpMailer::class, $smtpMock);

        (new SendEmailJob($log->id))->handle(app(EmailDispatchService::class));

        $this->assertSame('sent', $log->fresh()->status);
    }

    public function test_exchange_provider_update_forgets_stale_oauth_tokens(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $token = $admin->createToken('admin-panel')->plainTextToken;

        $create = $this->withToken($token)->postJson('/api/v1/admin/email-providers', [
            'name' => 'Exchange',
            'driver' => EmailDriver::Exchange->value,
            'from_address' => 'noreply@example.com',
            'is_default' => true,
            'is_active' => true,
            'config' => [
                'tenant_id' => 'tenant-a',
                'client_id' => 'azure-client-old',
                'client_secret' => 'secret-old',
                'auth_method' => 'client_credentials',
                'scope' => 'https://graph.microsoft.com/.default',
            ],
        ])->assertCreated();

        $id = (int) $create->json('data.id');

        app(ExchangeOauthTokenStore::class)->put(
            'azure-client-old',
            'stale-access',
            'stale-refresh',
            time() + 3600,
            'client_credentials',
        );

        $this->assertDatabaseHas('exchange_oauth_tokens', [
            'client_id' => 'azure-client-old',
        ]);

        $this->withToken($token)->putJson("/api/v1/admin/email-providers/{$id}", [
            'name' => 'Exchange',
            'driver' => EmailDriver::Exchange->value,
            'from_address' => 'noreply@example.com',
            'is_active' => true,
            'is_default' => true,
            'priority' => 10,
            'config' => [
                'tenant_id' => 'tenant-a',
                'client_id' => 'azure-client-old',
                'client_secret' => 'secret-new',
                'auth_method' => 'client_credentials',
                'scope' => 'https://graph.microsoft.com/.default',
            ],
        ])->assertOk();

        $this->assertDatabaseMissing('exchange_oauth_tokens', [
            'client_id' => 'azure-client-old',
        ]);
        $this->assertSame(0, ExchangeOauthToken::query()->count());
        $this->assertSame(
            'secret-new',
            EmailProvider::query()->findOrFail($id)->safeConfig()['client_secret'] ?? null
        );
    }
}
