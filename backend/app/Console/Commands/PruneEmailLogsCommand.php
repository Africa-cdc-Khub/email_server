<?php

namespace App\Console\Commands;

use App\Models\EmailLog;
use App\Services\EmailAttachmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneEmailLogsCommand extends Command
{
    protected $signature = 'emails:prune-logs
                            {--days=7 : Delete email logs older than this many days}
                            {--dry-run : Report how many rows would be deleted without deleting}';

    protected $description = 'Delete email logs (and their attachments) older than the retention window';

    public function handle(EmailAttachmentService $attachments): int
    {
        $days = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $query = EmailLog::query()
            ->where('created_at', '<', $cutoff)
            ->orderBy('id');

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info(sprintf('No email logs older than %d day(s).', $days));

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info(sprintf('Would delete %d email log(s) older than %s.', $total, $cutoff->toDateTimeString()));

            return self::SUCCESS;
        }

        $deleted = 0;

        $query->chunkById(100, function ($logs) use ($attachments, &$deleted): void {
            foreach ($logs as $log) {
                $meta = is_array($log->meta) ? $log->meta : [];
                $stored = is_array($meta['attachments'] ?? null) ? $meta['attachments'] : [];

                if ($stored !== []) {
                    $attachments->deleteStored($stored);
                }

                $dir = EmailAttachmentService::STORAGE_PREFIX.'/'.$log->id;
                if (Storage::disk(EmailAttachmentService::DISK)->exists($dir)) {
                    Storage::disk(EmailAttachmentService::DISK)->deleteDirectory($dir);
                }

                $log->delete();
                $deleted++;
            }
        });

        $this->info(sprintf(
            'Deleted %d email log(s) older than %d day(s) (before %s).',
            $deleted,
            $days,
            $cutoff->toDateTimeString(),
        ));

        return self::SUCCESS;
    }
}
