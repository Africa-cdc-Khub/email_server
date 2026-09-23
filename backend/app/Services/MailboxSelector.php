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
     * Pick the active mailbox with the most remaining 24h quota.
     * Ties break to the lowest mailbox id.
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
            ->sortBy([
                ['remaining_24h', 'desc'],
                ['id', 'asc'],
            ])
            ->values();

        if ($usage->isEmpty()) {
            throw new MailboxQuotaExhaustedException(
                'All enabled mailboxes for provider "'.$provider->name.'" have reached their 24h quota.'
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
     *     sent_24h: int,
     *     remaining_24h: int
     * }>
     */
    public function usageFor(EmailProvider $provider): array
    {
        $since = now()->subDay();
        $counts = EmailLog::query()
            ->selectRaw('from_address, COUNT(*) as sent_24h')
            ->where('status', 'sent')
            ->where('created_at', '>=', $since)
            ->whereNotNull('from_address')
            ->groupBy('from_address')
            ->pluck('sent_24h', 'from_address');

        return $provider->mailboxes()
            ->orderBy('id')
            ->get()
            ->map(function (ProviderMailbox $mailbox) use ($counts) {
                $sent = (int) ($counts[$mailbox->email] ?? 0);

                return [
                    'id' => $mailbox->id,
                    'email' => $mailbox->email,
                    'is_active' => (bool) $mailbox->is_active,
                    'daily_quota' => (int) $mailbox->daily_quota,
                    'sent_24h' => $sent,
                    'remaining_24h' => max(0, (int) $mailbox->daily_quota - $sent),
                ];
            })
            ->all();
    }
}
