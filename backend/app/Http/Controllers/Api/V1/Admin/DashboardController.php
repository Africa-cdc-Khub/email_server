<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use App\Models\EmailProvider;
use App\Models\ExternalIntegration;
use App\Models\User;
use App\Services\MailboxSelector;
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
        $pending = EmailLog::query()->where('status', 'pending');
        $this->scopeLogs($sentToday, $allowedIds);
        $this->scopeLogs($failedToday, $allowedIds);
        $this->scopeLogs($pending, $allowedIds);

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
                'emails_pending' => $pending->count(),
            ],
            'email_activity' => $this->emailActivityLastSevenDays($allowedIds),
            'default_provider' => $default ? [
                'id' => $default->id,
                'name' => $default->name,
                'driver' => $default->driver->value,
            ] : null,
            'mailbox_quotas' => $user->is_admin
                ? $this->mailboxQuotas(app(MailboxSelector::class))
                : [],
            'recent_logs' => $recent,
        ]);
    }

    /**
     * @return list<array{
     *     provider_id: int,
     *     provider_name: string,
     *     total_remaining_24h: int,
     *     total_daily_quota: int,
     *     total_remaining_1h: int|null,
     *     total_hourly_quota: int|null,
     *     mailboxes: list<array<string, mixed>>
     * }>
     */
    private function mailboxQuotas(MailboxSelector $selector): array
    {
        return EmailProvider::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('priority')
            ->orderBy('name')
            ->get()
            ->map(function (EmailProvider $provider) use ($selector) {
                $mailboxes = $selector->usageFor($provider);
                $totalRemaining24h = 0;
                $totalDailyQuota = 0;
                $totalRemaining1h = 0;
                $totalHourlyQuota = 0;
                $hasHourly = false;

                foreach ($mailboxes as $box) {
                    $totalRemaining24h += (int) ($box['remaining_24h'] ?? 0);
                    $totalDailyQuota += (int) ($box['daily_quota'] ?? 0);
                    if (array_key_exists('hourly_quota', $box) && $box['hourly_quota'] !== null) {
                        $hasHourly = true;
                        $totalHourlyQuota += (int) $box['hourly_quota'];
                        $totalRemaining1h += (int) ($box['remaining_1h'] ?? 0);
                    }
                }

                return [
                    'provider_id' => $provider->id,
                    'provider_name' => $provider->name,
                    'total_remaining_24h' => $totalRemaining24h,
                    'total_daily_quota' => $totalDailyQuota,
                    'total_remaining_1h' => $hasHourly ? $totalRemaining1h : null,
                    'total_hourly_quota' => $hasHourly ? $totalHourlyQuota : null,
                    'mailboxes' => $mailboxes,
                ];
            })
            ->values()
            ->all();
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
