<?php

namespace App\Console\Commands;

use App\Enums\UserApprovalStatus;
use App\Enums\UserRegistrationSource;
use App\Models\User;
use Illuminate\Console\Command;

class PurgePendingPublicRegistrationsCommand extends Command
{
    protected $signature = 'app:purge-pending-public-registrations
                            {--dry-run : List matching accounts without deleting}';

    protected $description = 'Delete public self-registration accounts still awaiting approval';

    public function handle(): int
    {
        $query = User::query()
            ->where('registration_source', UserRegistrationSource::Public)
            ->where('approval_status', UserApprovalStatus::Pending)
            ->where('is_admin', false);

        $count = $query->count();
        if ($count === 0) {
            $this->info('No pending public registration accounts found.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("Would delete {$count} pending public registration account(s):");
            $query->orderBy('id')->get(['id', 'email', 'name', 'organisation', 'created_at'])
                ->each(fn (User $u) => $this->line("  #{$u->id} {$u->email} ({$u->name})"));

            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info("Deleted {$deleted} pending public registration account(s).");

        return self::SUCCESS;
    }
}
