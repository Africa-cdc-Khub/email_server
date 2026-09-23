# Provider Mailbox Quotas Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Multiple from-addresses per email provider with least-used 24h load balancing, per-mailbox quotas, dashboard remaining-quota stats, and a from-mailbox picker on provider test sends.

**Architecture:** New `provider_mailboxes` table; `MailboxSelector` picks the active mailbox with the most remaining quota (counts from `email_logs` where `status=sent` and `from_address` matches). Exhaustion throws so existing provider fallback continues. Dashboard and provider APIs expose `sent_24h` / `remaining_24h`.

**Tech Stack:** Laravel, Sanctum admin auth, Vue 3 + Vuetify 3, PHPUnit via `docker exec email-server-app sh -lc 'cd /var/www/backend && vendor/bin/phpunit --filter=…'`.

## Global Constraints

- Selection: least remaining quota wins; rolling **24h** wall-clock; ties → lowest mailbox `id`.
- Default `daily_quota`: **10000**.
- Exhausted provider → fail attempt → existing provider fallback (not soft overrun, not queue-and-wait).
- Shared provider `from_name`; no per-mailbox display name.
- All drivers (Exchange, SMTP, SES, Log).
- Count **successful** sends only (`status = sent`).
- Provider test: require `from_mailbox_id`; no cross-provider fallback on test.
- Admin-only mailbox management and dashboard mailbox stats.
- Spec: `docs/superpowers/specs/2026-09-23-provider-mailbox-quotas-design.md`.

## File map

| File | Responsibility |
|---|---|
| `backend/database/migrations/2026_09_23_140000_create_provider_mailboxes_and_log_from_address.php` | Schema + backfill |
| `backend/app/Models/ProviderMailbox.php` | Mailbox model |
| `backend/app/Models/EmailProvider.php` | `mailboxes()` relation + legacy `from_address` sync helper |
| `backend/app/Models/EmailLog.php` | `from_address` fillable + include in `toLogArray` |
| `backend/app/Exceptions/MailboxQuotaExhaustedException.php` | Typed failure for fallback |
| `backend/app/Services/MailboxSelector.php` | Least-used selection + usage stats |
| `backend/app/Services/EmailDispatchService.php` | Select mailbox on send; persist `from_address`; honor exhaustion |
| `backend/app/Services/DynamicMailConfigService.php` / `ExchangeConfigurationResolver.php` | Prefer selected mailbox address over provider column |
| `backend/app/Http/Controllers/Api/V1/Admin/EmailProviderController.php` | Sync mailboxes on store/update; expose stats |
| `backend/app/Http/Requests/Api/V1/Admin/*EmailProviderRequest.php` | Validate nested mailboxes |
| `backend/app/Http/Requests/Api/V1/Admin/TestEmailProviderRequest.php` | `from_mailbox_id` |
| `backend/app/Http/Controllers/Api/V1/Admin/DashboardController.php` | `mailbox_quotas` |
| `backend/tests/Feature/ProviderMailboxQuotaTest.php` | Feature coverage |
| `frontend/src/views/ProviderFormView.vue` | Mailbox editor + test from picker |
| `frontend/src/views/DashboardView.vue` | Quota section |
| `frontend/src/components/dashboard/MailboxQuotaCard.vue` | Optional small component |

---

### Task 1: Schema + models (TDD)

**Files:**
- Create: migration above
- Create: `backend/app/Models/ProviderMailbox.php`
- Modify: `EmailProvider.php`, `EmailLog.php`
- Test: `backend/tests/Feature/ProviderMailboxQuotaTest.php` (migration/backfill assertions)

**Interfaces:**
- Produces: `EmailProvider::mailboxes(): HasMany`, `ProviderMailbox` fields `email`, `is_active`, `daily_quota`
- Produces: `EmailLog::$fillable` includes `from_address`

- [ ] **Step 1: Write failing test for backfill**

```php
public function test_migration_backfills_mailbox_from_provider_from_address(): void
{
    $provider = EmailProvider::factory()->create([
        'from_address' => 'notifications@example.com',
        'is_active' => true,
    ]);

    $this->assertDatabaseHas('provider_mailboxes', [
        'email_provider_id' => $provider->id,
        'email' => 'notifications@example.com',
        'daily_quota' => 10000,
        'is_active' => true,
    ]);
}
```

Note: with `RefreshDatabase`, the migration runs automatically — create provider **in the migration’s up()** only for existing rows. For the test, call a model helper `EmailProvider::syncLegacyFromAddressMailbox()` after create, OR assert via factory after implementing `booted`/`created` hook that ensures at least one mailbox when `from_address` set. Prefer explicit service method used by migration and controller:

```php
// In migration after create table:
DB::table('email_providers')->whereNotNull('from_address')->where('from_address', '!=', '')
  ->orderBy('id')->each(function ($row) {
      DB::table('provider_mailboxes')->insert([
          'email_provider_id' => $row->id,
          'email' => $row->from_address,
          'is_active' => true,
          'daily_quota' => 10000,
          'created_at' => now(),
          'updated_at' => now(),
      ]);
  });

Schema::table('email_logs', function (Blueprint $table) {
    $table->string('from_address')->nullable()->after('to');
    $table->index(['from_address', 'status', 'created_at']);
});
```

For the test without relying on re-running migration against seeded factory data:

```php
public function test_provider_can_have_multiple_mailboxes(): void
{
    $provider = EmailProvider::factory()->create(['from_address' => null]);
    $provider->mailboxes()->create(['email' => 'a@example.com', 'daily_quota' => 10000, 'is_active' => true]);
    $provider->mailboxes()->create(['email' => 'b@example.com', 'daily_quota' => 5000, 'is_active' => true]);
    $this->assertCount(2, $provider->fresh()->mailboxes);
}
```

- [ ] **Step 2: Run test — expect fail (no table/model)**

Run: `docker exec email-server-app sh -lc 'cd /var/www/backend && vendor/bin/phpunit --filter=ProviderMailboxQuotaTest'`

- [ ] **Step 3: Implement migration + models**

```php
// ProviderMailbox.php
protected $fillable = ['email_provider_id', 'email', 'is_active', 'daily_quota'];
protected function casts(): array {
    return ['is_active' => 'boolean', 'daily_quota' => 'integer'];
}
public function provider(): BelongsTo { return $this->belongsTo(EmailProvider::class, 'email_provider_id'); }
```

```php
// EmailProvider
public function mailboxes(): HasMany {
    return $this->hasMany(ProviderMailbox::class)->orderBy('id');
}

public function syncLegacyFromAddress(?string $email): void {
    $this->forceFill(['from_address' => $email])->save();
}
```

- [ ] **Step 4: Run test — expect pass**

- [ ] **Step 5: Commit**

```bash
git add backend/database/migrations backend/app/Models
git commit -m "Add provider_mailboxes table and from_address on email logs."
```

---

### Task 2: MailboxSelector + dispatch integration (TDD)

**Files:**
- Create: `MailboxQuotaExhaustedException.php`, `MailboxSelector.php`
- Modify: `EmailDispatchService.php`, `ExchangeConfigurationResolver.php` / transmit path
- Test: extend `ProviderMailboxQuotaTest.php`

**Interfaces:**
- Produces: `MailboxSelector::select(EmailProvider $provider): ProviderMailbox`
- Produces: `MailboxSelector::usageFor(EmailProvider $provider): list<array{id,email,is_active,daily_quota,sent_24h,remaining_24h}>`
- Consumes: `email_logs.from_address`, `status=sent`, `created_at >= now()-24h`

- [ ] **Step 1: Write failing selection tests**

```php
public function test_selects_mailbox_with_most_remaining_quota(): void
{
    $provider = EmailProvider::factory()->create(['from_address' => null, 'is_active' => true]);
    $a = $provider->mailboxes()->create(['email' => 'a@example.com', 'daily_quota' => 10, 'is_active' => true]);
    $b = $provider->mailboxes()->create(['email' => 'b@example.com', 'daily_quota' => 10, 'is_active' => true]);

    // 8 sends from A → remaining 2; B remaining 10
    for ($i = 0; $i < 8; $i++) {
        EmailLog::query()->create([
            'email_provider_id' => $provider->id,
            'to' => "u{$i}@example.com",
            'from_address' => 'a@example.com',
            'subject' => 'x',
            'status' => 'sent',
            'driver' => $provider->driver->value,
            'meta' => [],
        ]);
    }

    $chosen = app(MailboxSelector::class)->select($provider);
    $this->assertSame($b->id, $chosen->id);
}

public function test_exhausted_mailboxes_throw(): void
{
    $provider = EmailProvider::factory()->create(['from_address' => null, 'is_active' => true]);
    $provider->mailboxes()->create(['email' => 'a@example.com', 'daily_quota' => 1, 'is_active' => true]);
    EmailLog::query()->create([
        'email_provider_id' => $provider->id,
        'to' => 'u@example.com',
        'from_address' => 'a@example.com',
        'subject' => 'x',
        'status' => 'sent',
        'driver' => $provider->driver->value,
        'meta' => [],
    ]);

    $this->expectException(MailboxQuotaExhaustedException::class);
    app(MailboxSelector::class)->select($provider);
}

public function test_delivery_falls_back_when_primary_mailboxes_exhausted(): void
{
    // Primary provider: one mailbox quota 0 remaining
    // Secondary SMTP provider: active mailbox with quota
    // Mock PhpMailerSmtpMailer for secondary only
    // deliver() should send via secondary and set log email_provider_id to secondary
}
```

- [ ] **Step 2: Run — expect fail**

- [ ] **Step 3: Implement selector**

```php
class MailboxSelector
{
    public function select(EmailProvider $provider): ProviderMailbox
    {
        $usage = $this->usageFor($provider);
        $active = collect($usage)->where('is_active', true)->where('remaining_24h', '>', 0)
            ->sortBy([
                ['remaining_24h', 'desc'],
                ['id', 'asc'],
            ]);

        if ($active->isEmpty()) {
            throw new MailboxQuotaExhaustedException(
                'All mailboxes for provider "'.$provider->name.'" have reached their 24h quota.'
            );
        }

        $id = (int) $active->first()['id'];
        return ProviderMailbox::query()->findOrFail($id);
    }

    /** @return list<array<string, mixed>> */
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

        return $provider->mailboxes()->orderBy('id')->get()->map(function (ProviderMailbox $m) use ($counts) {
            $sent = (int) ($counts[$m->email] ?? 0);
            return [
                'id' => $m->id,
                'email' => $m->email,
                'is_active' => $m->is_active,
                'daily_quota' => $m->daily_quota,
                'sent_24h' => $sent,
                'remaining_24h' => max(0, $m->daily_quota - $sent),
            ];
        })->all();
    }
}
```

- [ ] **Step 4: Wire into `EmailDispatchService`**

In `sendViaProvider` / `transmit` loop, before send:

```php
$mailbox = $explicitMailbox ?? $this->mailboxSelector->select($provider);
$fromAddress = $mailbox->email;
// pass $fromAddress into phpMailer / Mail message from
// on success: $log->update([..., 'from_address' => $fromAddress])
```

Catch `MailboxQuotaExhaustedException` like other send failures so the candidate loop tries the next **provider**.

For `testProvider(EmailProvider $provider, string $to, ?string $senderIp, ?int $fromMailboxId)`: load mailbox by id belonging to provider; if missing throw 422; pass as `$explicitMailbox`; `allowFallback: false`.

Update `ExchangeConfigurationResolver::resolveFromAddress` callers in transmit to use the selected address (override), not only `$provider->from_address`.

- [ ] **Step 5: Run tests — expect pass**

- [ ] **Step 6: Commit**

```bash
git commit -m "Select least-used provider mailbox and fall back when quotas are exhausted."
```

---

### Task 3: Provider API sync + test from_mailbox_id (TDD)

**Files:**
- Modify: `StoreEmailProviderRequest`, `UpdateEmailProviderRequest`, `TestEmailProviderRequest`
- Modify: `EmailProviderController`
- Test: `ProviderMailboxQuotaTest` API cases

**Interfaces:**
- Produces: show/update JSON includes `mailboxes` with usage fields
- Produces: test accepts `from_mailbox_id`

- [ ] **Step 1: Failing API tests**

```php
public function test_admin_can_sync_mailboxes_on_update(): void { /* PUT with mailboxes array */ }
public function test_provider_test_requires_from_mailbox_id(): void { /* 422 without */ }
public function test_provider_test_uses_selected_mailbox(): void { /* assert log.from_address */ }
```

- [ ] **Step 2: Run — fail**

- [ ] **Step 3: Validation rules**

```php
'mailboxes' => ['sometimes', 'array', 'min:1'],
'mailboxes.*.id' => ['sometimes', 'nullable', 'integer'],
'mailboxes.*.email' => ['required', 'email', 'max:255'],
'mailboxes.*.is_active' => ['sometimes', 'boolean'],
'mailboxes.*.daily_quota' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
// TestEmailProviderRequest:
'from_mailbox_id' => ['required', 'integer', 'exists:provider_mailboxes,id'],
```

Controller: after save provider, sync mailboxes (upsert by id, delete missing), ensure ≥1 active if provider active, `syncLegacyFromAddress` to first active email. Attach usage via `MailboxSelector::usageFor`.

Validate test mailbox belongs to `$emailProvider`.

- [ ] **Step 4: Run — pass**

- [ ] **Step 5: Commit**

```bash
git commit -m "Sync provider mailboxes via API and require from mailbox on tests."
```

---

### Task 4: Dashboard mailbox quotas (TDD)

**Files:**
- Modify: `DashboardController.php`
- Modify: `DashboardView.vue` (+ optional `MailboxQuotaCard.vue`)
- Test: assert JSON key `mailbox_quotas` for admin

- [ ] **Step 1: Failing test**

```php
public function test_dashboard_includes_mailbox_quota_stats_for_admin(): void
{
    // create provider + mailboxes + some sent logs
    $this->withToken($adminToken)->getJson('/api/v1/admin/dashboard')
        ->assertOk()
        ->assertJsonStructure(['mailbox_quotas' => [['provider_id', 'provider_name', 'mailboxes' => [['email', 'daily_quota', 'sent_24h', 'remaining_24h']]]]]);
}
```

- [ ] **Step 2: Implement controller payload** — for each active provider (admin), map `MailboxSelector::usageFor`. Non-admins: empty array.

- [ ] **Step 3: Frontend section** — “Mailbox quotas (24h)” listing provider name, each mailbox email, `remaining / quota`, progress bar (`sent/quota`).

- [ ] **Step 4: Run tests + `npm run build`

- [ ] **Step 5: Commit**

```bash
git commit -m "Show per-mailbox 24h quota remaining on the admin dashboard."
```

---

### Task 5: Provider form UI

**Files:**
- Modify: `frontend/src/views/ProviderFormView.vue`

- [ ] **Step 1: Replace single from_address with mailbox list editor** (email, active switch, daily_quota number); keep from_name.
- [ ] **Step 2: On load, populate from `data.mailboxes`; on save send `mailboxes` array (include `id` when present).
- [ ] **Step 3: Test panel: `v-select` of active mailboxes → `from_mailbox_id`.
- [ ] **Step 4: Show sent/remaining from API when editing.
- [ ] **Step 5: `npm run build`; manual smoke on `/providers/:id/edit`.
- [ ] **Step 6: Commit**

```bash
git commit -m "Add mailbox editor and test from-address picker on providers."
```

---

### Task 6: Spec status + verify

- [ ] Mark design spec **Status: Implemented**
- [ ] Run: `vendor/bin/phpunit --filter='ProviderMailboxQuotaTest|EmailProviderFallbackTest|SmtpPhpMailerDispatchTest'`
- [ ] Commit + push if requested

```bash
git commit -m "Mark provider mailbox quotas design as implemented."
```

---

## Spec coverage check

| Spec requirement | Task |
|---|---|
| `provider_mailboxes` + backfill | 1 |
| `email_logs.from_address` | 1–2 |
| Least-used selection | 2 |
| Exhaustion → provider fallback | 2 |
| Shared from_name | 3–5 (unchanged field) |
| API sync + usage fields | 3 |
| Test from picker | 3, 5 |
| Dashboard quotas | 4 |
| Provider UI | 5 |

## Placeholder scan

None intentional — implementers must use the concrete classes/signatures above.
