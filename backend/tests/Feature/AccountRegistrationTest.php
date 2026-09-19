<?php

namespace Tests\Feature;

use App\Enums\EmailDriver;
use App\Enums\UserApprovalStatus;
use App\Models\EmailProvider;
use App\Models\ExternalIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_visitor_can_register_pending_account(): void
    {
        $this->postJson('/api/v1/admin/auth/register', [
            'name' => 'Partner User',
            'email' => 'partner@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'phone' => '+256700000000',
            'organisation' => 'Partner Org',
        ])->assertCreated()
            ->assertJsonMissingPath('token');

        $this->assertDatabaseHas('users', [
            'email' => 'partner@example.com',
            'approval_status' => 'pending',
            'is_admin' => 0,
            'is_active' => 0,
            'organisation' => 'Partner Org',
            'phone' => '+256700000000',
        ]);
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
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $pending = User::factory()->create([
            'approval_status' => UserApprovalStatus::Pending,
            'is_active' => false,
            'is_admin' => false,
            'totp_required' => false,
        ]);

        $this->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/admin/users/'.$pending->id.'/approve')
            ->assertOk();

        $pending->refresh();
        $this->assertTrue($pending->isApproved());
        $this->assertTrue($pending->is_active);
        $this->assertTrue($pending->totp_required);
    }

    public function test_admin_can_reject_pending_user(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $pending = User::factory()->create([
            'approval_status' => UserApprovalStatus::Pending,
            'is_active' => false,
            'is_admin' => false,
        ]);

        $this->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/admin/users/'.$pending->id.'/reject', [
                'reason' => 'Incomplete organisation details',
            ])
            ->assertOk();

        $pending->refresh();
        $this->assertSame(UserApprovalStatus::Rejected, $pending->approval_status);
        $this->assertFalse($pending->is_active);
        $this->assertSame('Incomplete organisation details', $pending->rejection_reason);
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
