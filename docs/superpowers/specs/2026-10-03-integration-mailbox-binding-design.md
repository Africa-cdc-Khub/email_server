# Integration mailbox binding + shared-weight soft downshift

**Date:** 2026-10-03  
**Status:** Approved (pending implementation)  
**Depends on:** [Mailbox send weights](./2026-09-23-mailbox-weights-design.md), [Provider mailbox quotas](./2026-09-23-provider-mailbox-quotas-design.md)  
**Surface:** `ExternalIntegration`, admin Integration edit UI, `MailboxSelector`, `EmailDispatchService`, config/env

## Problem

Operators want to pin a client integration to a **specific from-mailbox** (not only a provider). That client should use the bound mailbox whenever it has quota. Other clients may still use that mailbox, but with roughly **70% less share** than today so unbound mailboxes absorb more shared traffic—without hard exclusivity or new quota counters.

## Decisions (locked)

| Topic | Choice |
|---|---|
| Binding | Nullable `external_integrations.provider_mailbox_id` FK → `provider_mailboxes`, `nullOnDelete` |
| Bound client | Prefer bound mailbox **only**; if inactive or **quota hit** (daily/hourly), fall back to weighted auto-select on the same provider |
| Other clients | May still select that mailbox; apply soft weight factor (default **30%** of configured weight ≈ **70% reduction**) |
| Soft-weight algorithm | Approach **A**: `effective_weight = max(1, round(weight * shared_factor))` in auto-select scoring only |
| Adjustable percent | Env/config: shared traffic percent (default **30**). Formula: `shared_factor = clamp(percent, 1, 100) / 100` |
| Soft weight applies when | At least one **other** active integration has `provider_mailbox_id` equal to that mailbox |
| Bound client preferred pick | Does **not** apply soft weight to its own preferred mailbox |
| Provider binding | Unchanged: optional `email_provider_id`; mailbox must belong to that provider when set |
| Provider fallback / OTP queues | Unchanged |
| Explicit admin provider test | Unchanged (`from_mailbox_id` still hard-picks, no soft weight) |

## Config (adjustable percentage)

| Key | Env | Default | Meaning |
|---|---|---|---|
| `mail.bound_mailbox_shared_traffic_percent` | `MAIL_BOUND_MAILBOX_SHARED_TRAFFIC_PERCENT` | `30` | Percent of configured mailbox weight used for **shared** (non-preferring) selection when the mailbox is bound by another integration. `30` ⇒ ~70% less share vs unbound mailboxes. |

Validation at runtime: integer 1–100 (invalid/missing → 30).

Document in `backend/.env.example` and `backend/config/mail.php`. No per-integration override in v1.

**Example:** mailbox weight `10`, percent `30` → effective weight `3` for unbound clients. Bound client still uses that mailbox until quota hit.

## Schema

Add to `external_integrations`:

| Column | Type | Notes |
|---|---|---|
| `provider_mailbox_id` | nullable FK | `constrained('provider_mailboxes')->nullOnDelete()` |

Rules:

- If `provider_mailbox_id` set, `email_provider_id` must be set and mailbox.`email_provider_id` must match.
- Changing provider to one that does not own the mailbox → clear `provider_mailbox_id` (or reject update).

Model: fillable + `providerMailbox()` BelongsTo; keep `emailProvider()`.

## Selection (precise)

Extend `MailboxSelector::select(EmailProvider $provider, ?int $preferredMailboxId = null, ?ExternalIntegration $integration = null)` (or equivalent options):

1. **Preferred (bound) path** when `$preferredMailboxId` is set (from integration binding):
   - Load mailbox for provider + id.
   - If missing / inactive → fall through to auto-select (do not throw for binding miss; treat as unbound for this send).
   - If active and `remaining_24h > 0` and (`hourly_quota` null or `remaining_1h > 0`) → **return it**.
   - Else (quota hit) → fall through to auto-select.
2. **Auto-select** (existing deficit algorithm):
   - Eligible = active, remaining daily/hourly > 0.
   - Build set of mailbox ids currently bound by **other** active integrations (`provider_mailbox_id` not null, integration `is_active`, id ≠ current integration if any).
   - For each eligible row:  
     `base = max(1, weight)`  
     `factor = mailbox_id in bound_set ? shared_factor : 1.0`  
     `effective = max(1, (int) round(base * factor))`  
     `score = sent_24h / effective`
   - Pick lowest score; tie → lowest mailbox id.
3. If no eligible → `MailboxQuotaExhaustedException` (existing behavior / provider fallback).

Admin explicit test send continues to pass a hard mailbox id that bypasses soft weight and does not use the preferred/quota-fallback path above (keep current hard-pick semantics for tests).

## Dispatch

- `IntegrationMailController` / `EmailDispatchService`: when sending for an authenticated integration, pass `provider_mailbox_id` as preferred id into mailbox selection.
- Provider resolution unchanged (`email_provider_id` / default / request `provider_id` match rules).

## Admin API / UI

**API** (`ExternalIntegration` store/update/show/transform):

- Accept/return `provider_mailbox_id` (nullable int).
- Validate exists on `provider_mailboxes` and belongs to `email_provider_id`.
- Nested optional `provider_mailbox: { id, email }` for the form.

**UI** (`IntegrationFormView.vue` / integrations edit):

- Keep provider select.
- Add clearable mailbox select: options = active (or all) mailboxes for selected provider (load from provider show or mailboxes endpoint already used elsewhere).
- On provider change: clear mailbox if it no longer belongs to the new provider.
- Hint text: bound client prefers this mailbox; others share it at reduced weight (configured percent).

## Tests

- Bound client with remaining quota → always preferred mailbox.
- Bound client when preferred at daily or hourly limit → falls back to another eligible mailbox.
- Unbound client: mailbox bound by another integration scores with reduced effective weight; with equal `sent_24h`, unbound mailbox preferred more often.
- Config percent change (e.g. 10 vs 100) changes effective weight / selection outcome in a unit/feature test.
- Admin rejects mailbox from wrong provider; accepts matching mailbox; null clears binding.
- Multiple integrations bound to same mailbox: shared soft weight still applies once for auto-select; each bound client prefers that mailbox until quota hit.

## Out of scope

- Hard exclusivity (other clients forbidden from bound mailbox).
- Per-integration custom reduction percent (global env/config only in v1).
- Soft cap on remaining quota for unbound traffic (approach B/C).
- Binding across providers without `email_provider_id`.
- Changing provider-level `priority` or OTP queue routing.
