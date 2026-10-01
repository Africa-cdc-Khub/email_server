<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use App\Services\EmailDispatchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RetryPendingEmailsCommand extends Command
{
    protected $signature = 'emails:retry-pending
                            {--force : Ignore interval throttle and settings disable}';

    protected $description = 'Re-queue stuck pending and failed emails that still have a stored body';

    public function handle(EmailDispatchService $dispatch): int
    {
        $force = (bool) $this->option('force');
        $interval = SystemSetting::mailPendingRetrySeconds();

        if (! $force && $interval === 0) {
            $this->info('Email auto-retry is disabled (mail_pending_retry_seconds=0).');

            return self::SUCCESS;
        }

        $cacheKey = 'emails:retry-pending:last_run';

        if (! $force && $interval > 0) {
            $lastRun = Cache::get($cacheKey);
            if (is_numeric($lastRun) && (time() - (int) $lastRun) < $interval) {
                $this->info('Skipped — last run was within the configured interval.');

                return self::SUCCESS;
            }
        }

        $staleSeconds = $force ? 0 : max($interval, 45);
        $pending = $dispatch->retryAllPending(null, $staleSeconds);
        $failed = $dispatch->retryAllFailed(null, $staleSeconds);

        Cache::put($cacheKey, time(), max(3600, $interval * 10));

        $this->info(sprintf(
            'Re-queued %d pending and %d failed email(s); skipped %d.',
            $pending['queued'],
            $failed['queued'],
            $pending['skipped'] + $failed['skipped'],
        ));

        return self::SUCCESS;
    }
}
