<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use App\Models\EmailProvider;
use App\Models\ExternalIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $default = EmailProvider::query()->where('is_default', true)->first();

        return response()->json([
            'stats' => [
                'providers' => EmailProvider::query()->count(),
                'active_providers' => EmailProvider::query()->where('is_active', true)->count(),
                'integrations' => ExternalIntegration::query()->count(),
                'emails_sent_today' => EmailLog::query()->where('status', 'sent')->whereDate('created_at', today())->count(),
                'emails_failed_today' => EmailLog::query()->where('status', 'failed')->whereDate('created_at', today())->count(),
            ],
            'email_activity' => $this->emailActivityLastSevenDays(),
            'default_provider' => $default ? [
                'id' => $default->id,
                'name' => $default->name,
                'driver' => $default->driver->value,
            ] : null,
            'recent_logs' => EmailLog::query()
                ->with(['emailProvider:id,name', 'externalIntegration:id,name'])
                ->latest()
                ->limit(10)
                ->get()
                ->map->toLogArray()
                ->values(),
        ]);
    }

    /**
     * @return list<array{date: string, label: string, sent: int, failed: int}>
     */
    private function emailActivityLastSevenDays(): array
    {
        $activity = [];

        for ($daysAgo = 6; $daysAgo >= 0; $daysAgo--) {
            $date = today()->subDays($daysAgo);

            $activity[] = [
                'date' => $date->toDateString(),
                'label' => $date->isToday() ? 'Today' : $date->format('D'),
                'sent' => EmailLog::query()
                    ->where('status', 'sent')
                    ->whereDate('created_at', $date)
                    ->count(),
                'failed' => EmailLog::query()
                    ->where('status', 'failed')
                    ->whereDate('created_at', $date)
                    ->count(),
            ];
        }

        return $activity;
    }
}
