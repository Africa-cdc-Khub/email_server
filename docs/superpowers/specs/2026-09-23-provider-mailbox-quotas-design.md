# Provider mailbox load balancing & quotas

**Date:** 2026-09-23  
**Status:** Implemented (2026-09-23)  
**Surface:** Admin providers edit (`/providers/:id/edit`), dashboard (`/`), send/test paths

## Problem

Each Microsoft 365 / SMTP mailbox is subject to a daily send quota (typically **10,000 / 24 hours**). Today an `EmailProvider` has a single `from_address`, so all traffic for that provider burns one mailbox. Operators need:

1. Multiple from-addresses (mailboxes) per provider  
2. Automatic load balancing across enabled mailboxes  
3. Configurable per-mailbox daily quota  
4. Dashboard visibility of sent / remaining quota per mailbox  
5. Explicit from-address choice when sending a provider **test** email  

## Decisions (locked)

| Topic | Choice |
|---|---|
| Selection algorithm | Originally **least-used remaining quota**. **Superseded** by [mailbox weights](./2026-09-23-mailbox-weights-design.md): deficit `sent_24h / weight` among boxes with remaining quota (default weight 1 = equal share). |
| Exhausted provider | Fail that provider attempt → existing **provider fallback** chain continues (e.g. SMTP) **only when mailbox quota is depleted** (or no enabled mailboxes). Transport/API errors on Exchange do **not** fall back to SMTP. |
| Disabled mailboxes | Never selected for automatic sends; disabled-only providers count as exhausted for fallback. |
| Scope | **All** provider drivers (Exchange, SMTP, SES, Log) |
| Display name | **One shared** `from_name` on the provider |
| Storage | Dedicated **`provider_mailboxes`** table; usage counted from **`email_logs`** |
| Test send | Admin **must pick** the from mailbox when the provider has mailboxes |
| Default quota | **10 000** / rolling 24h for Exchange & others; SMTP **10 000/day** and **400/hour** (under Hostinger’s 12 000/day and 500/hour). Editable per mailbox. |

## Approach

### Schema

**`provider_mailboxes`**

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `email_provider_id` | FK → email_providers | cascade delete |
| `email` | string | validated email |
| `is_active` | boolean | default true |
| `daily_quota` | unsigned int | default 10000 |
| `created_at` / `updated_at` | timestamps | |

Unique index: `(email_provider_id, email)`.

**Migration of existing data**

- For each provider with a non-empty `from_address`, insert one active mailbox with that address and `daily_quota = 10000`.
- Keep `email_providers.from_address` as a **legacy mirror**: on mailbox create/update/delete, set it to the first active mailbox’s email (or null if none). Call sites that still read `from_address` keep working until fully switched to the selector.

**Send attribution**

- Persist chosen address on each log for quota counting:
  - Prefer a dedicated nullable column `email_logs.from_address` (indexed with `status`, `created_at`) for efficient 24h aggregates.
  - Also keep `meta.from_address` optional for debugging; column is the source of truth for counts.

### Selection service

`MailboxSelector` (or method on a small service):

1. Load active mailboxes for the provider.  
2. If none → throw (provider misconfigured).  
3. For each mailbox, `sent_24h` = count of `email_logs` where `status = sent`, `from_address = mailbox.email`, `created_at >= now() - 24 hours` (optionally also constrain `email_provider_id` for safety).  
4. `remaining = daily_quota - sent_24h`.  
5. Among mailboxes with `remaining > 0`, pick max remaining; tie → min `id`.  
6. If none → throw `MailboxQuotaExhaustedException` (message suitable for fallback).

**Integration with dispatch**

- In `EmailDispatchService` / `sendViaProvider`, resolve mailbox **after** provider is chosen and **before** send.  
- Use mailbox email as the SMTP/Exchange From address; provider `from_name` for display name.  
- On success: write `email_logs.from_address` (+ update `email_provider_id` / `driver` as today, including provider-fallback updates).  
- On `MailboxQuotaExhaustedException`: treat like a provider send failure so existing **provider fallback** tries the next active provider.  
- Explicit `provider_id` on admin Send Mail: still load-balances across that provider’s mailboxes unless a future override is added (out of scope).  
- **Provider test:** `from_mailbox_id` (or email) required; bypass least-used; still counts toward that mailbox’s quota; **no** cross-provider fallback on test (unchanged).

### Provider API / UI

**API**

- Provider show/update payloads include `mailboxes: [{ id, email, is_active, daily_quota, sent_24h, remaining_24h }]`.  
- Update accepts nested `mailboxes` array (sync: create/update/delete by id). Validation: ≥1 active mailbox when provider is active (or allow zero only if inactive — prefer requiring ≥1 active when `is_active`).  
- `POST .../email-providers/{id}/test` gains optional/required `from_mailbox_id` when mailboxes exist.

**UI (`ProviderFormView`)**

- Shared from-name field kept.  
- Mailbox editor: list of rows (email, enabled, daily quota), add/remove.  
- Show sent / remaining next to each row when editing.  
- Test panel: To + **From mailbox** select + captcha as today.

### Dashboard

- Extend dashboard stats with `mailbox_quotas`: grouped by provider  
  `{ provider_id, provider_name, mailboxes: [{ email, daily_quota, sent_24h, remaining_24h, is_active }] }`.  
- Admin-only (or all users who can see providers — **admin only** for v1).  
- UI: card/section “Mailbox quotas (24h)” with per-provider groups and remaining emphasized (e.g. bar or `remaining / quota`).

### Migration / backup package

- Export/import mailboxes with providers (by provider slug + mailbox email) in a follow-up if needed; **v1:** include in migration package when touching export schema, otherwise document as follow-up. Prefer including in migration export alongside providers for portability.

## Security & ops

- Only admins manage mailboxes.  
- Quota enforcement is application-side (Microsoft may still reject); we fail early when our counter says exhausted.  
- Counts are **successful sends only** (`status = sent`), not pending/failed.  
- Rolling window is wall-clock 24h from `now()`, not calendar day (better match “every 24hrs”).

## Out of scope

- Per-mailbox from-name  
- Weighted selection — **done in** [mailbox weights](./2026-09-23-mailbox-weights-design.md)  
- Queue-and-wait when exhausted  
- Soft overrun  
- Non-admin dashboard mailbox stats  
- Syncing quota from Microsoft Graph APIs  

## Success criteria

1. Provider edit supports multiple enabled from-addresses with per-box quotas.  
2. Normal sends pick the mailbox with the most remaining 24h capacity.  
3. Exhausted provider triggers existing provider fallback.  
4. Dashboard shows sent / remaining per mailbox.  
5. Provider test requires choosing a from mailbox.  
6. Feature tests cover selection, exhaustion→fallback, and API mailbox sync.

## Open follow-ups (non-blocking)

- Include mailboxes in migration backup/restore package.  
- Optional cache of 24h counts if dashboard queries get heavy.
