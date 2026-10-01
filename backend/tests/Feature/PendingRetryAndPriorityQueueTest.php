<?php

namespace Tests\Feature;

use App\Jobs\SendEmailJob;
use App\Models\EmailLog;
use App\Models\EmailProvider;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\EmailPriority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PendingRetryAndPriorityQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_otp_subjects_dispatch_to_priority_queue(): void
    {
        Queue::fake();

        $provider = EmailProvider::factory()->create([
            'is_default' => true,
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
            'password' => Hash::make('Secret123!'),
        ]);

        $token = $admin->createToken('admin-panel')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/admin/send-mail', [
                'to' => 'user@example.com',
                'subject' => 'Your sign-in code: 998877',
                'body' => '<p>Code</p>',
                'provider_id' => $provider->id,
            ])
            ->assertOk();

        Queue::assertPushed(SendEmailJob::class, function (SendEmailJob $job): bool {
            return $job->queue === EmailPriority::QUEUE_PRIORITY;
        });
    }

    public function test_dashboard_includes_pending_count(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
            'password' => Hash::make('Secret123!'),
        ]);

        $provider = EmailProvider::factory()->create([
            'is_default' => true,
            'is_active' => true,
        ]);

        EmailLog::query()->create([
            'email_provider_id' => $provider->id,
            'to' => 'a@example.com',
            'subject' => 'Pending',
            'status' => 'pending',
            'driver' => $provider->driver->value,
            'meta' => ['body' => '<p>x</p>', 'is_html' => true],
        ]);

        EmailLog::query()->create([
            'email_provider_id' => $provider->id,
            'to' => 'b@example.com',
            'subject' => 'Sent',
            'status' => 'sent',
            'driver' => $provider->driver->value,
            'meta' => ['body' => '<p>x</p>', 'is_html' => true],
        ]);

        $token = $admin->createToken('admin-panel')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('stats.emails_pending', 1);
    }

    public function test_admin_can_update_pending_retry_interval(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
            'password' => Hash::make('Secret123!'),
        ]);

        $token = $admin->createToken('admin-panel')->plainTextToken;

        $this->withToken($token)
            ->putJson('/api/v1/admin/mail-settings', [
                'mail_pending_retry_seconds' => 120,
            ])
            ->assertOk()
            ->assertJsonPath('data.mail_pending_retry_seconds', 120);

        $this->assertSame(120, SystemSetting::mailPendingRetrySeconds());
    }

    public function test_retry_pending_command_requeues_stale_pending(): void
    {
        Queue::fake();

        SystemSetting::setValue(SystemSetting::MAIL_PENDING_RETRY_SECONDS, 60);

        $provider = EmailProvider::factory()->create([
            'is_default' => true,
            'is_active' => true,
        ]);

        $stale = EmailLog::query()->create([
            'email_provider_id' => $provider->id,
            'to' => 'stale@example.com',
            'subject' => 'Stuck',
            'status' => 'pending',
            'driver' => $provider->driver->value,
            'meta' => ['body' => '<p>hello</p>', 'is_html' => true],
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        $fresh = EmailLog::query()->create([
            'email_provider_id' => $provider->id,
            'to' => 'fresh@example.com',
            'subject' => 'Fresh',
            'status' => 'pending',
            'driver' => $provider->driver->value,
            'meta' => ['body' => '<p>hello</p>', 'is_html' => true],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('emails:retry-pending', ['--force' => true]);

        // --force uses staleSeconds=0 so both are eligible
        Queue::assertPushed(SendEmailJob::class, 2);

        Queue::fake();
        \Illuminate\Support\Facades\Cache::forget('emails:retry-pending:last_run');

        // Query builder so Eloquent does not overwrite updated_at with "now"
        EmailLog::query()->whereKey($stale->id)->toBase()->update([
            'updated_at' => now()->subMinutes(5),
        ]);
        EmailLog::query()->whereKey($fresh->id)->toBase()->update([
            'updated_at' => now(),
        ]);

        Artisan::call('emails:retry-pending');

        Queue::assertPushed(SendEmailJob::class, fn (SendEmailJob $job) => $job->emailLogId === $stale->id);
        Queue::assertNotPushed(SendEmailJob::class, fn (SendEmailJob $job) => $job->emailLogId === $fresh->id);
    }

    public function test_retry_pending_command_also_requeues_stale_failed(): void
    {
        Queue::fake();

        SystemSetting::setValue(SystemSetting::MAIL_PENDING_RETRY_SECONDS, 60);

        $provider = EmailProvider::factory()->create([
            'is_default' => true,
            'is_active' => true,
        ]);

        $failed = EmailLog::query()->create([
            'email_provider_id' => $provider->id,
            'to' => 'failed@example.com',
            'subject' => 'Failed send',
            'status' => 'failed',
            'driver' => $provider->driver->value,
            'error_message' => 'boom',
            'meta' => ['body' => '<p>hello</p>', 'is_html' => true],
        ]);

        EmailLog::query()->whereKey($failed->id)->toBase()->update([
            'updated_at' => now()->subMinutes(5),
        ]);

        Artisan::call('emails:retry-pending');

        Queue::assertPushed(SendEmailJob::class, fn (SendEmailJob $job) => $job->emailLogId === $failed->id);
        $this->assertDatabaseHas('email_logs', [
            'id' => $failed->id,
            'status' => 'pending',
            'error_message' => null,
        ]);
    }

    public function test_prune_logs_deletes_rows_older_than_seven_days(): void
    {
        $provider = EmailProvider::factory()->create([
            'is_default' => true,
            'is_active' => true,
        ]);

        $old = EmailLog::query()->create([
            'email_provider_id' => $provider->id,
            'to' => 'old@example.com',
            'subject' => 'Old',
            'status' => 'sent',
            'driver' => $provider->driver->value,
            'meta' => ['body' => '<p>old</p>', 'is_html' => true],
        ]);
        $recent = EmailLog::query()->create([
            'email_provider_id' => $provider->id,
            'to' => 'recent@example.com',
            'subject' => 'Recent',
            'status' => 'sent',
            'driver' => $provider->driver->value,
            'meta' => ['body' => '<p>new</p>', 'is_html' => true],
        ]);

        EmailLog::query()->whereKey($old->id)->toBase()->update([
            'created_at' => now()->subDays(8),
            'updated_at' => now()->subDays(8),
        ]);
        EmailLog::query()->whereKey($recent->id)->toBase()->update([
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        Artisan::call('emails:prune-logs', ['--days' => 7]);

        $this->assertDatabaseMissing('email_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('email_logs', ['id' => $recent->id]);
    }
}
