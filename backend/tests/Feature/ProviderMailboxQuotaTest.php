<?php

namespace Tests\Feature;

use App\Enums\EmailDriver;
use App\Exceptions\MailboxQuotaExhaustedException;
use App\Jobs\SendEmailJob;
use App\Models\EmailLog;
use App\Models\EmailProvider;
use App\Models\User;
use App\Services\EmailDispatchService;
use App\Services\MailboxSelector;
use App\Services\PhpMailerSmtpMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ProviderMailboxQuotaTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
            'password' => Hash::make('Secret123!'),
        ]);

        return $admin->createToken('admin-panel')->plainTextToken;
    }

    public function test_provider_can_have_multiple_mailboxes(): void
    {
        $provider = EmailProvider::factory()->create(['from_address' => null]);
        $provider->mailboxes()->create(['email' => 'a@example.com', 'daily_quota' => 10000, 'is_active' => true]);
        $provider->mailboxes()->create(['email' => 'b@example.com', 'daily_quota' => 5000, 'is_active' => true]);

        $this->assertCount(2, $provider->fresh()->mailboxes);
    }

    public function test_creating_provider_with_from_address_seeds_mailbox(): void
    {
        $provider = EmailProvider::factory()->create([
            'from_address' => 'notifications@example.com',
            'driver' => EmailDriver::Exchange,
        ]);

        $this->assertDatabaseHas('provider_mailboxes', [
            'email_provider_id' => $provider->id,
            'email' => 'notifications@example.com',
            'daily_quota' => 10000,
            'is_active' => true,
        ]);
    }

    public function test_smtp_provider_seeds_mailbox_with_hostinger_default_quota(): void
    {
        $provider = EmailProvider::factory()->create([
            'from_address' => 'smtp@example.com',
            'driver' => EmailDriver::Smtp,
            'config' => [
                'host' => 'smtp.example.com',
                'port' => 587,
                'encryption' => 'tls',
            ],
        ]);

        $this->assertDatabaseHas('provider_mailboxes', [
            'email_provider_id' => $provider->id,
            'email' => 'smtp@example.com',
            'daily_quota' => 500,
            'is_active' => true,
        ]);
        $this->assertSame(500, $provider->defaultMailboxQuota());
    }

    public function test_selects_mailbox_with_most_remaining_quota(): void
    {
        $provider = EmailProvider::factory()->create(['from_address' => null, 'is_active' => true]);
        $a = $provider->mailboxes()->create(['email' => 'a@example.com', 'daily_quota' => 10, 'is_active' => true]);
        $b = $provider->mailboxes()->create(['email' => 'b@example.com', 'daily_quota' => 10, 'is_active' => true]);

        for ($i = 0; $i < 8; $i++) {
            EmailLog::query()->create([
                'email_provider_id' => $provider->id,
                'to' => "u{$i}@example.com",
                'from_address' => 'a@example.com',
                'subject' => 'x',
                'status' => 'sent',
                'driver' => $provider->driver->value,
                'meta' => [],
            ]);
        }

        $chosen = app(MailboxSelector::class)->select($provider);
        $this->assertSame($b->id, $chosen->id);
        $this->assertNotSame($a->id, $chosen->id);
    }

    public function test_disabled_mailboxes_are_never_selected(): void
    {
        $provider = EmailProvider::factory()->create(['from_address' => null, 'is_active' => true]);
        $provider->mailboxes()->create([
            'email' => 'disabled@example.com',
            'daily_quota' => 10000,
            'is_active' => false,
        ]);
        $active = $provider->mailboxes()->create([
            'email' => 'active@example.com',
            'daily_quota' => 10000,
            'is_active' => true,
        ]);

        $chosen = app(MailboxSelector::class)->select($provider);
        $this->assertSame($active->id, $chosen->id);
        $this->assertSame('active@example.com', $chosen->email);
    }

    public function test_only_disabled_mailboxes_count_as_exhausted_for_fallback(): void
    {
        $provider = EmailProvider::factory()->create(['from_address' => null, 'is_active' => true]);
        $provider->mailboxes()->create([
            'email' => 'notifications@example.com',
            'daily_quota' => 10000,
            'is_active' => false,
        ]);

        $this->expectException(MailboxQuotaExhaustedException::class);
        $this->expectExceptionMessage('no enabled from mailboxes');
        app(MailboxSelector::class)->select($provider);
    }

    public function test_exhausted_mailboxes_throw(): void
    {
        $provider = EmailProvider::factory()->create(['from_address' => null, 'is_active' => true]);
        $provider->mailboxes()->create(['email' => 'a@example.com', 'daily_quota' => 1, 'is_active' => true]);
        EmailLog::query()->create([
            'email_provider_id' => $provider->id,
            'to' => 'u@example.com',
            'from_address' => 'a@example.com',
            'subject' => 'x',
            'status' => 'sent',
            'driver' => $provider->driver->value,
            'meta' => [],
        ]);

        $this->expectException(MailboxQuotaExhaustedException::class);
        app(MailboxSelector::class)->select($provider);
    }

    public function test_delivery_falls_back_when_primary_mailboxes_exhausted(): void
    {
        $primary = EmailProvider::query()->create([
            'name' => 'Primary',
            'slug' => 'primary-smtp',
            'driver' => EmailDriver::Smtp,
            'config' => ['host' => 'broken.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'u', 'password' => 'p'],
            'from_address' => null,
            'is_default' => true,
            'is_active' => true,
            'priority' => 10,
        ]);
        $primary->mailboxes()->create(['email' => 'primary@example.com', 'daily_quota' => 1, 'is_active' => true]);
        EmailLog::query()->create([
            'email_provider_id' => $primary->id,
            'to' => 'prior@example.com',
            'from_address' => 'primary@example.com',
            'subject' => 'prior',
            'status' => 'sent',
            'driver' => EmailDriver::Smtp->value,
            'meta' => [],
        ]);

        $secondary = EmailProvider::query()->create([
            'name' => 'Secondary',
            'slug' => 'secondary-smtp',
            'driver' => EmailDriver::Smtp,
            'config' => ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'u', 'password' => 'p'],
            'from_address' => null,
            'is_default' => false,
            'is_active' => true,
            'priority' => 100,
        ]);
        $secondaryMailbox = $secondary->mailboxes()->create([
            'email' => 'secondary@example.com',
            'daily_quota' => 10000,
            'is_active' => true,
        ]);

        $smtpMock = Mockery::mock(PhpMailerSmtpMailer::class);
        $smtpMock->shouldReceive('send')
            ->once()
            ->withArgs(fn ($provider, $to, $subject, $body, $isHtml, $fromAddress) => $provider->id === $secondary->id
                && $fromAddress === 'secondary@example.com')
            ->andReturnNull();
        $this->app->instance(PhpMailerSmtpMailer::class, $smtpMock);

        $log = EmailLog::query()->create([
            'email_provider_id' => $primary->id,
            'to' => 'recipient@example.com',
            'subject' => 'Fallback quota',
            'status' => 'pending',
            'driver' => EmailDriver::Smtp->value,
            'meta' => ['body' => '<p>Hi</p>', 'is_html' => true, 'cc' => [], 'bcc' => []],
        ]);

        (new SendEmailJob($log->id))->handle(app(EmailDispatchService::class));

        $fresh = $log->fresh();
        $this->assertSame('sent', $fresh->status);
        $this->assertSame($secondary->id, $fresh->email_provider_id);
        $this->assertSame('secondary@example.com', $fresh->from_address);
        $this->assertSame($secondaryMailbox->id, $fresh->meta['from_mailbox_id'] ?? null);
    }

    public function test_admin_can_sync_mailboxes_on_update(): void
    {
        $provider = EmailProvider::factory()->create(['from_address' => 'old@example.com']);
        $existing = $provider->mailboxes()->first();

        $this->withToken($this->adminToken())
            ->putJson('/api/v1/admin/email-providers/'.$provider->id, [
                'mailboxes' => [
                    ['id' => $existing?->id, 'email' => 'one@example.com', 'is_active' => true, 'daily_quota' => 8000],
                    ['email' => 'two@example.com', 'is_active' => true, 'daily_quota' => 12000],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.mailboxes.0.email', 'one@example.com')
            ->assertJsonPath('data.mailboxes.0.daily_quota', 8000)
            ->assertJsonPath('data.mailboxes.1.email', 'two@example.com');

        $this->assertSame(2, $provider->fresh()->mailboxes()->count());
        $this->assertSame('one@example.com', $provider->fresh()->from_address);
    }

    public function test_provider_test_requires_from_mailbox_id(): void
    {
        config(['captcha.enabled' => false]);
        $provider = EmailProvider::factory()->create(['from_address' => 'a@example.com']);

        $this->withToken($this->adminToken())
            ->postJson('/api/v1/admin/email-providers/'.$provider->id.'/test', [
                'to' => 'admin@example.com',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from_mailbox_id']);
    }

    public function test_dashboard_includes_mailbox_quota_stats_for_admin(): void
    {
        $provider = EmailProvider::factory()->create([
            'from_address' => null,
            'is_active' => true,
            'name' => 'Quota Provider',
        ]);
        $provider->mailboxes()->create([
            'email' => 'box@example.com',
            'daily_quota' => 100,
            'is_active' => true,
        ]);
        EmailLog::query()->create([
            'email_provider_id' => $provider->id,
            'to' => 'u@example.com',
            'from_address' => 'box@example.com',
            'subject' => 'x',
            'status' => 'sent',
            'driver' => $provider->driver->value,
            'meta' => [],
        ]);

        $this->withToken($this->adminToken())
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'mailbox_quotas' => [[
                    'provider_id',
                    'provider_name',
                    'mailboxes' => [['email', 'daily_quota', 'sent_24h', 'remaining_24h']],
                ]],
            ])
            ->assertJsonFragment([
                'email' => 'box@example.com',
                'daily_quota' => 100,
                'sent_24h' => 1,
                'remaining_24h' => 99,
            ]);
    }
}
