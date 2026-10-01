<?php

namespace App\Console\Commands;

use App\Enums\UserApprovalStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class EnsureAdminUserCommand extends Command
{
    protected $signature = 'app:ensure-admin
                            {--force-password : Always rewrite password from ADMIN_PASSWORD}';

    protected $description = 'Ensure the ADMIN_EMAIL user exists, is approved/active admin, and can sign in with ADMIN_PASSWORD';

    public function handle(): int
    {
        $email = trim($this->envString('ADMIN_EMAIL', 'andrewa@africacdc.org'));
        $password = $this->envString('ADMIN_PASSWORD');
        $name = trim($this->envString('ADMIN_NAME', 'Super Admin'));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('ADMIN_EMAIL is missing or invalid.');

            return self::FAILURE;
        }

        if ($password === '') {
            $this->error('ADMIN_PASSWORD is empty — set it in docker/.env (passed into the app container).');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();
        $created = false;
        $passwordUpdated = false;

        if ($user === null) {
            $user = new User([
                'email' => $email,
                'name' => $name !== '' ? $name : 'Super Admin',
            ]);
            $created = true;
        }

        $forcePassword = (bool) $this->option('force-password')
            || filter_var($this->envString('ADMIN_RESET_PASSWORD', 'false'), FILTER_VALIDATE_BOOL);

        if (
            $created
            || $forcePassword
            || ! Hash::check($password, (string) $user->password)
        ) {
            $user->password = Hash::make($password);
            $passwordUpdated = true;
        }

        $user->name = $name !== '' ? $name : ($user->name ?: 'Super Admin');
        $user->is_admin = true;
        $user->is_active = true;
        $user->approval_status = UserApprovalStatus::Approved;
        if ($user->approved_at === null) {
            $user->approved_at = now();
        }
        $user->rejected_at = null;
        $user->rejection_reason = null;
        $user->save();

        // Other seeded admins must not block operations with a stale password.
        User::query()
            ->where('is_admin', true)
            ->where('email', '!=', $email)
            ->update(['is_active' => false]);

        $this->info(sprintf(
            'Admin %s — %s%s',
            $email,
            $created ? 'created' : 'updated',
            $passwordUpdated ? '; password synced from ADMIN_PASSWORD' : '; password already matched env',
        ));

        return self::SUCCESS;
    }

    private function envString(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? env($key, $default);
        }

        return (string) ($value ?? $default);
    }
}
