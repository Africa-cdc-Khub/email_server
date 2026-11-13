<?php

namespace Tests\Feature;

use App\Enums\EmailDriver;
use App\Jobs\SendEmailJob;
use App\Models\EmailProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminSendMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.captcha.enabled' => false]);
    }

    public function test_authenticated_admin_can_queue_manual_email(): void
    {
        $provider = EmailProvider::query()->create([
            'name' => 'Log',
            'slug' => 'log',
            'driver' => EmailDriver::Log,
            'config' => [],
            'is_default' => true,
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $token = $admin->createToken('admin-panel')->plainTextToken;

        Queue::fake();

        $this->withToken($token)->postJson('/api/v1/admin/send-mail', [
            'to' => 'recipient@example.com',
            'subject' => 'Manual test',
            'body' => '<p>Hello from admin panel</p>',
            'provider_id' => $provider->id,
        ])->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonStructure(['log_id', 'message']);

        Queue::assertPushed(SendEmailJob::class);
    }

    public function test_admin_can_queue_email_with_attachment(): void
    {
        $provider = EmailProvider::query()->create([
            'name' => 'Log',
            'slug' => 'log-attach',
            'driver' => EmailDriver::Log,
            'config' => [],
            'is_default' => true,
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $token = $admin->createToken('admin-panel')->plainTextToken;

        Queue::fake();

        $this->withToken($token)->postJson('/api/v1/admin/send-mail', [
            'to' => 'recipient@example.com',
            'subject' => 'With file',
            'body' => '<p>See attached</p>',
            'provider_id' => $provider->id,
            'attachments' => [
                [
                    'filename' => 'note.txt',
                    'content' => base64_encode('hello'),
                    'content_type' => 'text/plain',
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('attachment_count', 1);

        Queue::assertPushed(SendEmailJob::class);
    }

    public function test_admin_can_queue_email_to_pasted_csv_recipients(): void
    {
        $provider = EmailProvider::query()->create([
            'name' => 'Log',
            'slug' => 'log-multi',
            'driver' => EmailDriver::Log,
            'config' => [],
            'is_default' => true,
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $token = $admin->createToken('admin-panel')->plainTextToken;

        Queue::fake();

        $this->withToken($token)->postJson('/api/v1/admin/send-mail', [
            'to' => "name,email\nAlice,one@example.com\nBob,two@example.com; three@example.com",
            'subject' => 'Bulk paste',
            'body' => '<p><strong>Hello</strong> team</p>',
            'is_html' => true,
            'provider_id' => $provider->id,
        ])->assertOk()
            ->assertJsonPath('queued_count', 3)
            ->assertJsonCount(3, 'log_ids');

        Queue::assertPushed(SendEmailJob::class, 3);
    }

    public function test_admin_can_queue_plain_text_email(): void
    {
        $provider = EmailProvider::query()->create([
            'name' => 'Log',
            'slug' => 'log-plain',
            'driver' => EmailDriver::Log,
            'config' => [],
            'is_default' => true,
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $token = $admin->createToken('admin-panel')->plainTextToken;

        Queue::fake();

        $this->withToken($token)->postJson('/api/v1/admin/send-mail', [
            'to' => ['plain@example.com'],
            'subject' => 'Plain',
            'body' => 'Hello without markup',
            'is_html' => false,
            'provider_id' => $provider->id,
        ])->assertOk()
            ->assertJsonPath('queued_count', 1);

        Queue::assertPushed(SendEmailJob::class, 1);
    }

    public function test_guest_cannot_send_manual_email(): void
    {
        $this->postJson('/api/v1/admin/send-mail', [
            'to' => 'recipient@example.com',
            'subject' => 'Manual test',
            'body' => 'Hello',
        ])->assertUnauthorized();
    }

    public function test_admin_can_schedule_email_for_later(): void
    {
        $provider = EmailProvider::query()->create([
            'name' => 'Log',
            'slug' => 'log-schedule',
            'driver' => EmailDriver::Log,
            'config' => [],
            'is_default' => true,
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $token = $admin->createToken('admin-panel')->plainTextToken;

        Queue::fake();

        $sendAt = now()->addHours(2)->toIso8601String();

        $this->withToken($token)->postJson('/api/v1/admin/send-mail', [
            'to' => ['later@example.com'],
            'subject' => 'Scheduled',
            'body' => '<p>Later</p>',
            'provider_id' => $provider->id,
            'send_at' => $sendAt,
        ])->assertOk()
            ->assertJsonPath('scheduled', true)
            ->assertJsonPath('queued_count', 1);

        Queue::assertPushed(SendEmailJob::class, 1);
        $this->assertDatabaseHas('email_logs', [
            'to' => 'later@example.com',
            'status' => 'pending',
        ]);
    }

    public function test_send_mail_rejects_more_than_6000_recipients(): void
    {
        EmailProvider::query()->create([
            'name' => 'Log',
            'slug' => 'log-limit',
            'driver' => EmailDriver::Log,
            'config' => [],
            'is_default' => true,
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $token = $admin->createToken('admin-panel')->plainTextToken;

        $emails = [];
        for ($i = 1; $i <= 6001; $i++) {
            $emails[] = "user{$i}@example.com";
        }

        $this->withToken($token)->postJson('/api/v1/admin/send-mail', [
            'to' => $emails,
            'subject' => 'Too many',
            'body' => '<p>Nope</p>',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
    }

    public function test_admin_can_pause_and_schedule_retry_by_subject(): void
    {
        $provider = EmailProvider::query()->create([
            'name' => 'Log',
            'slug' => 'log-queue-control',
            'driver' => EmailDriver::Log,
            'config' => [],
            'is_default' => true,
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $token = $admin->createToken('admin-panel')->plainTextToken;

        Queue::fake();

        $this->withToken($token)->postJson('/api/v1/admin/send-mail', [
            'to' => ['one@example.com', 'two@example.com'],
            'subject' => 'Busy hour blast',
            'body' => '<p>Hold please</p>',
            'provider_id' => $provider->id,
        ])->assertOk()
            ->assertJsonPath('queued_count', 2);

        $this->withToken($token)
            ->getJson('/api/v1/admin/send-mail/pending-subjects')
            ->assertOk()
            ->assertJsonFragment(['subject' => 'Busy hour blast', 'pending_count' => 2]);

        $this->withToken($token)
            ->getJson('/api/v1/admin/send-mail/queue-by-subject?subject='.urlencode('Busy hour blast'))
            ->assertOk()
            ->assertJsonPath('pending_count', 2)
            ->assertJsonPath('paused_count', 0);

        $this->withToken($token)->postJson('/api/v1/admin/send-mail/pause-by-subject', [
            'subject' => 'Busy hour blast',
            'reason' => 'Busy hours',
        ])->assertOk()
            ->assertJsonPath('paused', 2);

        $this->assertDatabaseHas('email_logs', [
            'subject' => 'Busy hour blast',
            'to' => 'one@example.com',
            'status' => 'failed',
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/admin/send-mail/queue-by-subject?subject='.urlencode('Busy hour blast'))
            ->assertOk()
            ->assertJsonPath('pending_count', 0)
            ->assertJsonPath('paused_count', 2);

        Queue::fake();

        $sendAt = now()->addHours(3)->toIso8601String();

        $this->withToken($token)->postJson('/api/v1/admin/send-mail/schedule-retry-by-subject', [
            'subject' => 'Busy hour blast',
            'send_at' => $sendAt,
        ])->assertOk()
            ->assertJsonPath('queued', 2)
            ->assertJsonPath('scheduled', true);

        $this->assertDatabaseHas('email_logs', [
            'subject' => 'Busy hour blast',
            'to' => 'one@example.com',
            'status' => 'pending',
        ]);

        Queue::assertPushed(SendEmailJob::class, 2);
    }

    public function test_admin_can_fail_and_clear_all_pending_emails(): void
    {
        $provider = EmailProvider::query()->create([
            'name' => 'Log',
            'slug' => 'log-clear-all',
            'driver' => EmailDriver::Log,
            'config' => [],
            'is_default' => true,
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $token = $admin->createToken('admin-panel')->plainTextToken;

        Queue::fake();

        $this->withToken($token)->postJson('/api/v1/admin/send-mail', [
            'to' => ['a@example.com', 'b@example.com', 'c@example.com'],
            'subject' => 'Clear me',
            'body' => '<p>Stop</p>',
            'provider_id' => $provider->id,
        ])->assertOk()
            ->assertJsonPath('queued_count', 3);

        $this->withToken($token)
            ->getJson('/api/v1/admin/send-mail/pending-subjects')
            ->assertOk()
            ->assertJsonPath('total_pending', 3);

        $this->withToken($token)->postJson('/api/v1/admin/send-mail/fail-and-clear-all-pending', [
            'confirm' => true,
            'reason' => 'Emergency stop',
        ])->assertOk()
            ->assertJsonPath('cleared', 3)
            ->assertJsonPath('total_pending', 0);

        $this->assertDatabaseMissing('email_logs', [
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('email_logs', [
            'to' => 'a@example.com',
            'status' => 'failed',
        ]);

        $this->withToken($token)->postJson('/api/v1/admin/send-mail/fail-and-clear-all-pending', [
            'confirm' => false,
        ])->assertStatus(422);
    }
}
