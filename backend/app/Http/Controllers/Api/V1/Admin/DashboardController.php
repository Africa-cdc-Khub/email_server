<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use App\Models\EmailProvider;
use App\Models\ExternalIntegration;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $allowedIds = $user->allowedExternalIntegrationIds();

        $default = EmailProvider::query()->where('is_default', true)->first();

        $integrationsQuery = ExternalIntegration::query();
        if ($allowedIds !== null) {
            $integrationsQuery->whereIn('id', $allowedIds === [] ? [0] : $allowedIds);
        }

        $sentToday = EmailLog::query()->where('status', 'sent')->whereDate('created_at', today());
        $failedToday = EmailLog::query()->where('status', 'failed')->whereDate('created_at', today());
        $this->scopeLogs($sentToday, $allowedIds);
        $this->scopeLogs($failedToday, $allowedIds);

        $recent = EmailLog::query()
            ->with(['emailProvider:id,name', 'externalIntegration:id,name'])
            ->tap(fn (Builder $q) => $this->scopeLogs($q, $allowedIds))
            ->latest()
            ->limit(10)
            ->get()
            ->map->toLogArray()
            ->values();

        return response()->json([
            'stats' => [
                'providers' => $user->is_admin ? EmailProvider::query()->count() : EmailProvider::query()->where('is_active', true)->count(),
                'active_providers' => EmailProvider::query()->where('is_active', true)->count(),
                'integrations' => $integrationsQuery->count(),
                'emails_sent_today' => $sentToday->count(),
                'emails_failed_today' => $failedToday->count(),
            ],
            'email_activity' => $this->emailActivityLastSevenDays($allowedIds),
            'default_provider' => $default ? [
                'id' => $default->id,
                'name' => $default->name,
                'driver' => $default->driver->value,
            ] : null,
            'recent_logs' => $recent,
        ]);
    }

    /**
     * @param  list<int>|null  $allowedIds
     * @return list<array{date: string, label: string, sent: int, failed: int}>
     */
    private function emailActivityLastSevenDays(?array $allowedIds): array
    {
        $activity = [];

        for ($daysAgo = 6; $daysAgo >= 0; $daysAgo--) {
            $date = today()->subDays($daysAgo);

            $sent = EmailLog::query()->where('status', 'sent')->whereDate('created_at', $date);
            $failed = EmailLog::query()->where('status', 'failed')->whereDate('created_at', $date);
            $this->scopeLogs($sent, $allowedIds);
            $this->scopeLogs($failed, $allowedIds);

            $activity[] = [
                'date' => $date->toDateString(),
                'label' => $date->isToday() ? 'Today' : $date->format('D'),
                'sent' => $sent->count(),
                'failed' => $failed->count(),
            ];
        }

        return $activity;
    }

    /**
     * @param  list<int>|null  $allowedIds
     */
    private function scopeLogs(Builder $query, ?array $allowedIds): void
    {
        if ($allowedIds === null) {
            return;
        }

        if ($allowedIds === []) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->whereIn('external_integration_id', $allowedIds);
    }
}
