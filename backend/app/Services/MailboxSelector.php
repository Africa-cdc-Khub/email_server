<?php

namespace App\Services;

use App\Exceptions\MailboxQuotaExhaustedException;
use App\Models\EmailLog;
use App\Models\EmailProvider;
use App\Models\ExternalIntegration;
use App\Models\ProviderMailbox;
use InvalidArgumentException;

class MailboxSelector
{
    /**
     * Pick a from-mailbox for the provider.
     *
     * - strictMailbox=true: hard-pick (admin tests); throws if missing/disabled.
     * - mailboxId set, strict=false: prefer that mailbox while quota remains; else weighted.
     * - otherwise: weighted deficit among eligible mailboxes (soft-weighting bound boxes).
     */
    public function select(
        EmailProvider $provider,
        ?int $mailboxId = null,
        ?ExternalIntegration $integration = null,
        bool $strictMailbox = false,
    ): ProviderMailbox {
        if ($mailboxId !== null && $strictMailbox) {
            return $this->selectStrict($provider, $mailboxId);
        }

        if ($mailboxId !== null && ! $strictMailbox) {
            $preferred = $this->tryPreferred($provider, $mailboxId);
            if ($preferred !== null) {
                return $preferred;
            }
        }

        return $this->selectWeighted($provider, $integration);
    }

    private function selectStrict(EmailProvider $provider, int $mailboxId): ProviderMailbox
    {
        $mailbox = ProviderMailbox::query()
            ->where('email_provider_id', $provider->id)
            ->whereKey($mailboxId)
            ->first();

        if ($mailbox === null) {
            throw new InvalidArgumentException('From mailbox not found for this provider.');
        }

        if (! $mailbox->is_active) {
            throw new InvalidArgumentException('From mailbox is disabled.');
        }

        return $mailbox;
    }

    private function tryPreferred(EmailProvider $provider, int $mailboxId): ?ProviderMailbox
    {
        $mailbox = ProviderMailbox::query()
            ->where('email_provider_id', $provider->id)
            ->whereKey($mailboxId)
            ->first();

        if ($mailbox === null || ! $mailbox->is_active) {
            return null;
        }

        $usage = collect($this->usageFor($provider))->firstWhere('id', $mailbox->id);
        if ($usage === null) {
            return null;
        }

        if ((int) $usage['remaining_24h'] <= 0) {
            return null;
        }

        if (array_key_exists('remaining_1h', $usage) && $usage['remaining_1h'] !== null && (int) $usage['remaining_1h'] <= 0) {
            return null;
        }

        return $mailbox;
    }

    private function selectWeighted(EmailProvider $provider, ?ExternalIntegration $integration): ProviderMailbox
    {
        if (! $provider->mailboxes()->where('is_active', true)->exists()) {
            throw new MailboxQuotaExhaustedException(
                'Provider "'.$provider->name.'" has no enabled from mailboxes.'
            );
        }

        $boundIds = $this->mailboxIdsBoundByOthers($provider->id, $integration?->id);
        $factor = $this->sharedTrafficPercent() / 100.0;

        $usage = collect($this->usageFor($provider))
            ->where('is_active', true)
            ->where('remaining_24h', '>', 0)
            ->filter(fn (array $row) => ($row['remaining_1h'] ?? 1) > 0)
            ->map(function (array $row) use ($boundIds, $factor): array {
                $weight = max(1, (int) $row['weight']);
                if (isset($boundIds[(int) $row['id']])) {
                    $weight = max(1, (int) round($weight * $factor));
                }
                $row['score'] = ((int) $row['sent_24h']) / $weight;

                return $row;
            })
            ->sortBy([
                ['score', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        if ($usage->isEmpty()) {
            throw new MailboxQuotaExhaustedException(
                'All enabled mailboxes for provider "'.$provider->name.'" have reached their send quota.'
            );
        }

        return ProviderMailbox::query()
            ->where('is_active', true)
            ->findOrFail((int) $usage->first()['id']);
    }

    /**
     * @return array<int, true>
     */
    private function mailboxIdsBoundByOthers(int $providerId, ?int $exceptIntegrationId): array
    {
        $query = ExternalIntegration::query()
            ->where('is_active', true)
            ->whereNotNull('provider_mailbox_id')
            ->whereHas('providerMailbox', fn ($q) => $q->where('email_provider_id', $providerId));

        if ($exceptIntegrationId !== null) {
            $query->where('id', '!=', $exceptIntegrationId);
        }

        return $query->pluck('provider_mailbox_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    private function sharedTrafficPercent(): int
    {
        $percent = (int) config('mail.bound_mailbox_shared_traffic_percent', 30);

        return max(1, min(100, $percent));
    }

    /**
     * @return list<array{
     *     id: int,
     *     email: string,
     *     is_active: bool,
     *     daily_quota: int,
     *     hourly_quota: int|null,
     *     weight: int,
     *     sent_24h: int,
     *     sent_1h: int,
     *     remaining_24h: int,
     *     remaining_1h: int|null
     * }>
     */
    public function usageFor(EmailProvider $provider): array
    {
        $sinceDay = now()->subDay();
        $sinceHour = now()->subHour();

        $counts24h = EmailLog::query()
            ->selectRaw('from_address, COUNT(*) as sent_count')
            ->where('status', 'sent')
            ->where('created_at', '>=', $sinceDay)
            ->whereNotNull('from_address')
            ->groupBy('from_address')
            ->pluck('sent_count', 'from_address');

        $counts1h = EmailLog::query()
            ->selectRaw('from_address, COUNT(*) as sent_count')
            ->where('status', 'sent')
            ->where('created_at', '>=', $sinceHour)
            ->whereNotNull('from_address')
            ->groupBy('from_address')
            ->pluck('sent_count', 'from_address');

        return $provider->mailboxes()
            ->orderBy('id')
            ->get()
            ->map(function (ProviderMailbox $mailbox) use ($counts24h, $counts1h) {
                $sent24h = (int) ($counts24h[$mailbox->email] ?? 0);
                $sent1h = (int) ($counts1h[$mailbox->email] ?? 0);
                $weight = max(1, (int) $mailbox->weight);
                $hourlyQuota = $mailbox->hourly_quota !== null ? (int) $mailbox->hourly_quota : null;

                return [
                    'id' => $mailbox->id,
                    'email' => $mailbox->email,
                    'is_active' => (bool) $mailbox->is_active,
                    'daily_quota' => (int) $mailbox->daily_quota,
                    'hourly_quota' => $hourlyQuota,
                    'weight' => $weight,
                    'sent_24h' => $sent24h,
                    'sent_1h' => $sent1h,
                    'remaining_24h' => max(0, (int) $mailbox->daily_quota - $sent24h),
                    'remaining_1h' => $hourlyQuota === null ? null : max(0, $hourlyQuota - $sent1h),
                ];
            })
            ->all();
    }
}
