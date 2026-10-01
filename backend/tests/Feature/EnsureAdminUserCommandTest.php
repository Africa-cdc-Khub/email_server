<?php

namespace Tests\Feature;

use App\Enums\UserApprovalStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EnsureAdminUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_ensure_admin_creates_approved_admin_from_env(): void
    {
        putenv('ADMIN_EMAIL=ops@example.com');
        putenv('ADMIN_PASSWORD=EnvSecret99!');
        putenv('ADMIN_NAME=Ops Admin');
        $_ENV['ADMIN_EMAIL'] = 'ops@example.com';
        $_ENV['ADMIN_PASSWORD'] = 'EnvSecret99!';
        $_ENV['ADMIN_NAME'] = 'Ops Admin';

        $this->artisan('app:ensure-admin')
            ->assertSuccessful();

        $user = User::query()->where('email', 'ops@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->is_admin);
        $this->assertTrue($user->is_active);
        $this->assertSame(UserApprovalStatus::Approved, $user->approval_status);
        $this->assertTrue(Hash::check('EnvSecret99!', $user->password));
    }

    public function test_ensure_admin_resyncs_password_when_env_differs(): void
    {
        $user = User::factory()->create([
            'email' => 'ops@example.com',
            'password' => Hash::make('OldPassword1!'),
            'is_admin' => true,
            'is_active' => true,
            'approval_status' => UserApprovalStatus::Pending,
        ]);

        putenv('ADMIN_EMAIL=ops@example.com');
        putenv('ADMIN_PASSWORD=NewPassword2!');
        $_ENV['ADMIN_EMAIL'] = 'ops@example.com';
        $_ENV['ADMIN_PASSWORD'] = 'NewPassword2!';

        $this->artisan('app:ensure-admin')
            ->assertSuccessful();

        $user->refresh();
        $this->assertTrue(Hash::check('NewPassword2!', $user->password));
        $this->assertSame(UserApprovalStatus::Approved, $user->approval_status);
        $this->assertTrue($user->is_active);
    }
}
