<?php

namespace Tests\Feature;

use App\Enums\EmailDriver;
use App\Jobs\SendEmailJob;
use App\Models\EmailLog;
use App\Models\EmailProvider;
use App\Models\ExternalIntegration;
use App\Models\User;
use App\Services\EmailDispatchService;
use App\Services\PhpMailerSmtpMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ProviderFleetDefaultSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_set_default_rebinds_integrations_and_pending_logs_to_new_provider(): void
    {
        $exchange = EmailProvider::query()->create([
            'name' => 'Default Exchange',
            'slug' => 'default-exchange',
            'driver' => EmailDriver::Exchange,
            'config' => [
                'tenant_id' => 'tenant',
                'client_id' => 'client',
                'client_secret' => 'secret',
                'auth_method' => 'client_credentials',
            ],
            'from_address' => 'notifications@africacdc.org',
            'is_default' => true,
            'is_active' => true,
        ]);

        $smtp = EmailProvider::query()->create([
            'name' => 'MS Communications',
            'slug' => 'ms-communications',
            'driver' => EmailDriver::Smtp,
            'config' => [
                'host' => 'smtp.example.com',
                'port' => 587,
                'encryption' => 'tls',
                'username' => 'mailer',
                'password' => 'secret',
            ],
            'from_address' => 'noreply@africacdc.info',
            'is_default' => false,
            'is_active' => true,
        ]);

        $integration = ExternalIntegration::query()->create([
            'name' => 'Staff Portal',
            'slug' => 'staff-portal',
            'api_key_hash' => Hash::make('test-key'),
            'api_key_prefix' => 'test',
            'email_provider_id' => $exchange->id,
            'is_active' => true,
        ]);

        $pending = EmailLog::query()->create([
            'email_provider_id' => $exchange->id,
            'external_integration_id' => $integration->id,
            'to' => 'user@example.com',
            'subject' => 'Still queued on Exchange',
            'status' => 'pending',
            'driver' => EmailDriver::Exchange->value,
            'meta' => [
                'body' => '<p>Hi</p>',
                'is_html' => true,
                'source' => 'integration',
            ],
        ]);

        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $token = $admin->createToken('admin-panel')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/admin/email-providers/{$smtp->id}/set-default")
            ->assertOk()
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('fleet.integrations_updated', 1)
            ->assertJsonPath('fleet.pending_logs_updated', 1);

        $this->assertNull($integration->fresh()->email_provider_id);
        $this->assertTrue((bool) $smtp->fresh()->is_default);
        $this->assertFalse((bool) $exchange->fresh()->is_default);

        $pending->refresh();
        $this->assertSame($smtp->id, $pending->email_provider_id);
        $this->assertSame(EmailDriver::Smtp->value, $pending->driver);
    }

    public function test_disabling_provider_moves_sticky_integrations_to_follow_default(): void
    {
        $smtp = EmailProvider::query()->create([
            'name' => 'MS Communications',
            'slug' => 'ms-smtp',
            'driver' => EmailDriver::Smtp,
            'config' => [
                'host' => 'smtp.example.com',
                'port' => 587,
                'password' => 'secret',
            ],
            'from_address' => 'noreply@africacdc.info',
            'is_default' => true,
            'is_active' => true,
        ]);

        $exchange = EmailProvider::query()->create([
            'name' => 'Default Exchange',
            'slug' => 'exchange-off',
            'driver' => EmailDriver::Exchange,
            'config' => [
                'tenant_id' => 'tenant',
                'client_id' => 'client',
                'client_secret' => 'secret',
            ],
            'from_address' => 'notifications@africacdc.org',
            'is_default' => false,
            'is_active' => true,
        ]);

        $integration = ExternalIntegration::query()->create([
            'name' => 'Pinned App',
            'slug' => 'pinned-app',
            'api_key_hash' => Hash::make('key'),
            'api_key_prefix' => 'pin',
            'email_provider_id' => $exchange->id,
            'is_active' => true,
        ]);

        $pending = EmailLog::query()->create([
            'email_provider_id' => $exchange->id,
            'external_integration_id' => $integration->id,
            'to' => 'user@example.com',
            'subject' => 'Pending on Exchange',
            'status' => 'pending',
            'driver' => EmailDriver::Exchange->value,
            'meta' => ['body' => '<p>x</p>', 'is_html' => true, 'source' => 'integration'],
        ]);

        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $token = $admin->createToken('admin-panel')->plainTextToken;

        $this->withToken($token)
            ->putJson("/api/v1/admin/email-providers/{$exchange->id}", [
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('fleet.integrations_updated', 1)
            ->assertJsonPath('fleet.pending_logs_updated', 1);

        $this->assertNull($integration->fresh()->email_provider_id);
        $this->assertSame($smtp->id, $pending->fresh()->email_provider_id);
    }

    public function test_inactive_sticky_pin_falls_back_to_default_on_deliver(): void
    {
        $smtp = EmailProvider::query()->create([
            'name' => 'MS Communications',
            'slug' => 'ms-deliver',
            'driver' => EmailDriver::Smtp,
            'config' => [
                'host' => 'smtp.example.com',
                'port' => 587,
                'encryption' => 'tls',
                'username' => 'mailer',
                'password' => 'secret',
            ],
            'from_address' => 'noreply@africacdc.info',
            'is_default' => true,
            'is_active' => true,
        ]);

        $exchange = EmailProvider::query()->create([
            'name' => 'Default Exchange',
            'slug' => 'exchange-inactive',
            'driver' => EmailDriver::Exchange,
            'config' => [
                'tenant_id' => 'tenant',
                'client_id' => 'client',
                'client_secret' => 'secret',
            ],
            'from_address' => 'notifications@africacdc.org',
            'is_default' => false,
            'is_active' => false,
        ]);

        $log = EmailLog::query()->create([
            'email_provider_id' => $exchange->id,
            'to' => 'user@example.com',
            'subject' => 'Should use SMTP default',
            'status' => 'pending',
            'driver' => EmailDriver::Exchange->value,
            'meta' => [
                'body' => '<p>Hello</p>',
                'is_html' => true,
                'cc' => [],
                'bcc' => [],
                'source' => 'integration',
            ],
        ]);

        $smtpMock = Mockery::mock(PhpMailerSmtpMailer::class);
        $smtpMock->shouldReceive('send')
            ->once()
            ->withArgs(fn (EmailProvider $p) => $p->id === $smtp->id)
            ->andReturnNull();
        $this->app->instance(PhpMailerSmtpMailer::class, $smtpMock);

        (new SendEmailJob($log->id))->handle(app(EmailDispatchService::class));

        $fresh = $log->fresh();
        $this->assertSame('sent', $fresh->status);
        $this->assertSame($smtp->id, $fresh->email_provider_id);
        $this->assertSame(EmailDriver::Smtp->value, $fresh->driver);
    }
}
