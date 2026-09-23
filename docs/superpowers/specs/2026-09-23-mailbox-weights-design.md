# Mailbox send weights

**Date:** 2026-09-23  
**Status:** Implemented (2026-09-23)  
**Depends on:** [Provider mailbox load balancing & quotas](./2026-09-23-provider-mailbox-quotas-design.md)  
**Surface:** `MailboxSelector`, provider API/UI, dashboard mailbox stats

## Problem

Operators want some from-addresses to carry a larger share of traffic than others on the same provider (e.g. primary Graph mailbox vs backup). Today selection is **most remaining 24h quota**, which equalizes burn rate, not intentional share.

## Decisions (locked)

| Topic | Choice |
|---|---|
| Algorithm | **Deficit / target-share**: among eligible mailboxes, pick lowest `sent_24h / weight` (tie → lowest mailbox `id`) |
| Quota interaction | **Weight first; quota is a hard stop only** (option B). Eligible = `is_active` and `remaining_24h > 0`. Exhausted boxes are skipped; weight is not diluted back toward emptier boxes. |
| Default weight | **1** for every mailbox (equal share when weights are untouched) |
| Column | `provider_mailboxes.weight` unsigned integer, min **1**, default **1** |
| Explicit / test send | Unchanged — chosen mailbox bypasses weighting |
| Provider fallback | Unchanged — all eligible exhausted → `MailboxQuotaExhaustedException` |
| Scope | All drivers that already use `MailboxSelector` |

## Selection (precise)

1. Load mailbox usage for the provider (existing `usageFor`: `sent_24h`, `remaining_24h`, plus `weight`).  
2. Eligible = active and `remaining_24h > 0`.  
3. If none → throw `MailboxQuotaExhaustedException` (or “no enabled mailboxes” if none active).  
4. Score each eligible mailbox as `sent_24h / weight` (float).  
5. Pick the minimum score; ties → minimum `id`.  
6. Return that `ProviderMailbox`.

**Examples**

- Weights `[1, 1]`, zero sends → both score `0`; pick lowest id (equal share over time).  
- Weights `[3, 1]`, sends `[0, 0]` → both score `0`; pick lowest id, then keep preferring the under-target box until ~3:1.  
- Weights `[3, 1]`, A exhausted → only B eligible → B always wins until A has remaining again.

This **replaces** “most remaining quota wins” for automatic selection.

## Schema

Add to `provider_mailboxes`:

| Column | Type | Notes |
|---|---|---|
| `weight` | unsigned int | default `1`, not null |

Migration: backfill existing rows to `1`.

Model / `syncMailboxes`: accept and persist `weight`; default `1` when omitted.

## API / UI

**Payloads** (`mailboxes[]` on show/update/store, dashboard `mailbox_quotas`):

- Include `weight` alongside `email`, `is_active`, `daily_quota`, `sent_24h`, `remaining_24h`.

**Validation**

- `mailboxes.*.weight`: sometimes, integer, min 1, max e.g. 10000.

**Provider form**

- Per-mailbox **Weight** input (default 1).  
- Optional hint: higher weight ≈ larger share of sends among boxes that still have quota.

**Dashboard**

- Show weight next to each mailbox (read-only).

## Tests

- Equal weights → with uneven prior sends, prefer the lower `sent/weight` (same as equalizing counts).  
- Weights 3 vs 1 with zero history → still picks a mailbox; after several selects with recorded sends, heavier box receives more.  
- Exhausted high-weight box → low-weight box selected.  
- API sync persists `weight`; default remains 1 when omitted.  
- Existing exhaustion / disabled / fallback tests still pass (update “most remaining” test to weighted deficit semantics).

## Out of scope

- Weighted random / WRR token state  
- Cross-provider weights  
- Auto-normalizing weights to percentages in the UI  
- Changing the rolling 24h quota window

## Success criteria

1. New mailboxes default to weight `1`.  
2. Automatic sends prefer mailboxes furthest below their weight share.  
3. Quota still hard-stops selection; disabled boxes never selected.  
4. Admin can set weight on provider edit; dashboard shows it.  
5. Feature tests cover equal weights, unequal weights, and exhausted high-weight fallback within the provider.

## Spec self-review

- No placeholders; algorithm and defaults concrete.  
- Consistent with option B (weight steers; quota only gates eligibility).  
- Does not reopen per-mailbox `from_name` or Graph quota sync.  
- Parent design’s “least-used remaining” selection is superseded for auto-select only.
