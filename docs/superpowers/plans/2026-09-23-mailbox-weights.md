# Mailbox Send Weights Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add per-mailbox `weight` so automatic sends allocate traffic by deficit `sent_24h / weight` among quota-eligible boxes (default weight 1 = equal share).

**Architecture:** Extend `provider_mailboxes` with `weight`, update `MailboxSelector::select` / `usageFor`, wire validation + `syncMailboxes`, and expose weight in provider form + dashboard. Quota remains a hard eligibility gate only.

**Tech Stack:** Laravel 12, PHPUnit, Vue 3 + Vuetify, existing `ProviderMailboxQuotaTest`.

**Spec:** `docs/superpowers/specs/2026-09-23-mailbox-weights-design.md`

## Global Constraints

- Default weight: `1`; min `1`; max `10000`
- Selection: eligible = active + `remaining_24h > 0`; pick min `sent_24h / weight`; tie → min `id`
- Explicit/test mailbox id bypasses weighting
- Do not commit unless the user asks (session preference)

---

### Task 1: Schema + model + selector (TDD)

**Files:**
- Create: `backend/database/migrations/2026_09_23_160000_add_weight_to_provider_mailboxes.php`
- Modify: `backend/app/Models/ProviderMailbox.php`
- Modify: `backend/app/Models/EmailProvider.php` (`syncMailboxes`)
- Modify: `backend/app/Services/MailboxSelector.php`
- Modify: `backend/tests/Feature/ProviderMailboxQuotaTest.php`

- [ ] **Step 1: Write failing tests** in `ProviderMailboxQuotaTest`:

```php
public function test_selects_by_weight_deficit_not_remaining_quota(): void
{
    $provider = EmailProvider::factory()->create(['from_address' => null, 'is_active' => true]);
    $heavy = $provider->mailboxes()->create([
        'email' => 'heavy@example.com', 'daily_quota' => 100, 'weight' => 3, 'is_active' => true,
    ]);
    $light = $provider->mailboxes()->create([
        'email' => 'light@example.com', 'daily_quota' => 100, 'weight' => 1, 'is_active' => true,
    ]);
    // light has more remaining (never sent) but heavy is further below target share after 1 heavy send
    EmailLog::query()->create([
        'email_provider_id' => $provider->id,
        'to' => 'u@example.com',
        'from_address' => 'heavy@example.com',
        'subject' => 'x',
        'status' => 'sent',
        'driver' => $provider->driver->value,
        'meta' => [],
    ]);
    // scores: heavy 1/3≈0.33, light 0/1=0 → pick light
    $chosen = app(MailboxSelector::class)->select($provider);
    $this->assertSame($light->id, $chosen->id);
}

public function test_equal_weights_prefer_lower_sent_count(): void
{
    // same as old least-used when weights equal
    ...
}

public function test_exhausted_high_weight_falls_to_low_weight(): void
{
    $provider = EmailProvider::factory()->create(['from_address' => null, 'is_active' => true]);
    $provider->mailboxes()->create([
        'email' => 'heavy@example.com', 'daily_quota' => 1, 'weight' => 10, 'is_active' => true,
    ]);
    $light = $provider->mailboxes()->create([
        'email' => 'light@example.com', 'daily_quota' => 10, 'weight' => 1, 'is_active' => true,
    ]);
    EmailLog::query()->create([/* heavy@ sent */]);
    $chosen = app(MailboxSelector::class)->select($provider);
    $this->assertSame($light->id, $chosen->id);
}

public function test_admin_can_sync_mailbox_weights(): void
{
    // putJson mailboxes with weight 5 → assertJsonPath weight 5; omit weight → defaults 1
}
```

Replace `test_selects_mailbox_with_most_remaining_quota` with equal-weight deficit behavior (or rename).

- [ ] **Step 2: Run tests — expect fail** (no `weight` column / old algorithm)

```bash
cd docker && docker compose run --rm --no-deps --entrypoint bash app -lc \
  'cd /var/www/backend && vendor/bin/phpunit --filter=ProviderMailboxQuotaTest'
```

- [ ] **Step 3: Migration**

```php
Schema::table('provider_mailboxes', function (Blueprint $table) {
    $table->unsignedInteger('weight')->default(1)->after('daily_quota');
});
```

- [ ] **Step 4: Model fillable/casts; `syncMailboxes` payload**

```php
'weight' => max(1, (int) ($row['weight'] ?? 1)),
```

- [ ] **Step 5: `MailboxSelector`**

```php
$usage = collect($this->usageFor($provider))
    ->where('is_active', true)
    ->where('remaining_24h', '>', 0)
    ->map(fn ($row) => $row + [
        'score' => $row['sent_24h'] / max(1, (int) $row['weight']),
    ])
    ->sortBy([['score', 'asc'], ['id', 'asc']])
    ->values();
```

Include `weight` in `usageFor` return arrays.

- [ ] **Step 6: Tests pass**

---

### Task 2: API validation + responses

**Files:**
- Modify: `backend/app/Http/Requests/Api/V1/Admin/StoreEmailProviderRequest.php`
- Modify: `backend/app/Http/Requests/Api/V1/Admin/UpdateEmailProviderRequest.php`
- Modify: `backend/app/Http/Controllers/Api/V1/Admin/EmailProviderController.php` (fallback mailbox row includes `weight: 1`)

- [ ] Add `'mailboxes.*.weight' => ['sometimes', 'integer', 'min:1', 'max:10000']`
- [ ] Fallback transform mailbox includes `'weight' => 1`
- [ ] Re-run `ProviderMailboxQuotaTest` including sync weight test

---

### Task 3: Frontend form + dashboard

**Files:**
- Modify: `frontend/src/views/ProviderFormView.vue`
- Modify: `frontend/src/views/DashboardView.vue`

- [ ] Add `weight` to `MailboxRow`, defaults `1` on add/load/save payload
- [ ] Weight number field on each mailbox row; update help text to describe weight share + quota hard stop
- [ ] Dashboard: show `weight` (e.g. `weight ×N`) next to email
- [ ] `npm run build`

---

### Task 4: Spec status + verify

**Files:**
- Modify: `docs/superpowers/specs/2026-09-23-mailbox-weights-design.md` → Status: Implemented

- [ ] Full `ProviderMailboxQuotaTest` green
- [ ] Frontend build succeeds

---

## Spec coverage

| Spec item | Task |
|---|---|
| `weight` column default 1 | 1 |
| Deficit selection + quota gate | 1 |
| Exhausted high-weight → low | 1 |
| API sync / validation | 1–2 |
| Provider form + dashboard | 3 |
| Tests | 1–2 |
| Explicit/test unchanged | no code change (bypass path already exists) |
