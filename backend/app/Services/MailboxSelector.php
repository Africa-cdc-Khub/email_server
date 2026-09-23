<?php

namespace App\Services;

use App\Exceptions\MailboxQuotaExhaustedException;
use App\Models\EmailLog;
use App\Models\EmailProvider;
use App\Models\ProviderMailbox;
use InvalidArgumentException;

class MailboxSelector
{
    /**
     * Pick the active mailbox furthest below its weight share (min sent_24h / weight).
     * Eligible when remaining daily (and hourly, if set) quota is > 0. Ties → lowest mailbox id.
     */
    public function select(EmailProvider $provider, ?int $explicitMailboxId = null): ProviderMailbox
    {
        if ($explicitMailboxId !== null) {
            $mailbox = ProviderMailbox::query()
                ->where('email_provider_id', $provider->id)
                ->whereKey($explicitMailboxId)
                ->first();

            if ($mailbox === null) {
                throw new InvalidArgumentException('From mailbox not found for this provider.');
            }

            if (! $mailbox->is_active) {
                throw new InvalidArgumentException('From mailbox is disabled.');
            }

            return $mailbox;
        }

        if (! $provider->mailboxes()->where('is_active', true)->exists()) {
            throw new MailboxQuotaExhaustedException(
                'Provider "'.$provider->name.'" has no enabled from mailboxes.'
            );
        }

        $usage = collect($this->usageFor($provider))
            ->where('is_active', true)
            ->where('remaining_24h', '>', 0)
            ->filter(fn (array $row) => ($row['remaining_1h'] ?? 1) > 0)
            ->map(function (array $row): array {
                $weight = max(1, (int) $row['weight']);
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
