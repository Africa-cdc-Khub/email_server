<?php

namespace Tests\Feature;

use App\Enums\EmailDriver;
use App\Enums\UserApprovalStatus;
use App\Enums\UserRegistrationSource;
use App\Jobs\SendEmailJob;
use App\Models\EmailLog;
use App\Models\EmailProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AccountRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.captcha.enabled' => false]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    public function test_public_registration_endpoint_is_removed(): void
    {
        $this->postJson('/api/v1/admin/auth/register', [
            'name' => 'Partner User',
            'email' => 'partner@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'phone' => '+256700000000',
            'organisation' => 'Partner Org',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', [
            'email' => 'partner@example.com',
        ]);
    }

    public function test_purge_command_deletes_pending_public_registrations(): void
    {
        User::factory()->create([
            'email' => 'keep-admin@example.com',
            'is_admin' => true,
            'is_active' => true,
            'approval_status' => UserApprovalStatus::Approved,
            'registration_source' => UserRegistrationSource::System,
        ]);
        User::factory()->create([
            'email' => 'keep-approved-public@example.com',
            'is_admin' => false,
            'is_active' => true,
            'approval_status' => UserApprovalStatus::Approved,
            'registration_source' => UserRegistrationSource::Public,
        ]);
        User::factory()->create([
            'email' => 'delete-pending@example.com',
            'is_admin' => false,
            'is_active' => false,
            'approval_status' => UserApprovalStatus::Pending,
            'registration_source' => UserRegistrationSource::Public,
        ]);

        Artisan::call('app:purge-pending-public-registrations');

        $this->assertDatabaseMissing('users', ['email' => 'delete-pending@example.com']);
        $this->assertDatabaseHas('users', ['email' => 'keep-admin@example.com']);
        $this->assertDatabaseHas('users', ['email' => 'keep-approved-public@example.com']);
    }

    public function test_admin_user_index_filters_and_meta_counts(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        User::factory()->create([
            'approval_status' => UserApprovalStatus::Pending,
            'registration_source' => UserRegistrationSource::Public,
            'is_active' => false,
            'is_admin' => false,
        ]);
        User::factory()->create([
            'approval_status' => UserApprovalStatus::Approved,
            'registration_source' => UserRegistrationSource::System,
            'is_active' => true,
            'is_admin' => false,
        ]);

        $token = $admin->createToken('admin-panel')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/admin/users')
            ->assertOk()
            ->assertJsonPath('meta.pending_count', 1)
            ->assertJsonPath('meta.public_count', 1)
            ->assertJsonPath('meta.system_count', 2)
            ->assertJsonPath('meta.total_count', 3);

        $this->withToken($token)
            ->getJson('/api/v1/admin/users?approval_status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.approval_status', 'pending');

        $this->withToken($token)
            ->getJson('/api/v1/admin/users?registration_source=public')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.registration_source', 'public');
    }

    public function test_pending_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'pending@example.com',
            'password' => 'SecurePass123!',
            'approval_status' => UserApprovalStatus::Pending,
            'is_active' => false,
            'is_admin' => false,
        ]);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'pending@example.com',
            'password' => 'SecurePass123!',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Your account is awaiting administrator approval.');
    }

    public function test_rejected_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'rejected@example.com',
            'password' => 'SecurePass123!',
            'approval_status' => UserApprovalStatus::Rejected,
            'is_active' => false,
            'is_admin' => false,
        ]);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'rejected@example.com',
            'password' => 'SecurePass123!',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Your account registration was not approved.');
    }

    public function test_admin_can_approve_pending_user(): void
    {
        EmailProvider::query()->create([
            'name' => 'Log',
            'slug' => 'log',
            'driver' => EmailDriver::Log,
            'config' => [],
            'is_default' => true,
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $pending = User::factory()->create([
            'email' => 'partner-approve@example.com',
            'approval_status' => UserApprovalStatus::Pending,
            'is_active' => false,
            'is_admin' => false,
            'totp_required' => false,
        ]);

        Queue::fake();

        $this->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/admin/users/'.$pending->id.'/approve')
            ->assertOk();

        $pending->refresh();
        $this->assertTrue($pending->isApproved());
        $this->assertTrue($pending->is_active);
        $this->assertTrue($pending->totp_required);

        Queue::assertPushed(SendEmailJob::class);
        $log = EmailLog::query()->where('to', 'partner-approve@example.com')->first();
        $this->assertNotNull($log);
        $this->assertSame('account_approved', $log->meta['source'] ?? null);
        $this->assertStringContainsString('/login', (string) ($log->meta['body'] ?? ''));
    }

    public function test_admin_can_reject_pending_user(): void
    {
        EmailProvider::query()->create([
            'name' => 'Log',
            'slug' => 'log-reject',
            'driver' => EmailDriver::Log,
            'config' => [],
            'is_default' => true,
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $pending = User::factory()->create([
            'email' => 'partner-reject@example.com',
            'approval_status' => UserApprovalStatus::Pending,
            'is_active' => false,
            'is_admin' => false,
        ]);

        Queue::fake();

        $this->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/admin/users/'.$pending->id.'/reject', [
                'reason' => 'Incomplete organisation details',
            ])
            ->assertOk();

        $pending->refresh();
        $this->assertSame(UserApprovalStatus::Rejected, $pending->approval_status);
        $this->assertFalse($pending->is_active);
        $this->assertSame('Incomplete organisation details', $pending->rejection_reason);

        Queue::assertPushed(SendEmailJob::class);
        $log = EmailLog::query()->where('to', 'partner-reject@example.com')->first();
        $this->assertNotNull($log);
        $this->assertSame('account_rejected', $log->meta['source'] ?? null);
        $this->assertStringContainsString('Incomplete organisation details', (string) ($log->meta['body'] ?? ''));
    }

    public function test_approved_user_can_login(): void
    {
        $user = User::factory()->create([
            'email' => 'approved@example.com',
            'password' => 'SecurePass123!',
            'approval_status' => UserApprovalStatus::Approved,
            'is_active' => true,
            'is_admin' => false,
            'totp_required' => false,
        ]);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'approved@example.com',
            'password' => 'SecurePass123!',
        ])->assertOk()
            ->assertJsonStructure(['token', 'user']);
    }
}
