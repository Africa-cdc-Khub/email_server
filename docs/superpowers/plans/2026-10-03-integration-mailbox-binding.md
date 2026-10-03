# Integration Mailbox Binding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let admins bind a client integration to a specific from-mailbox; that client prefers it until quota is hit, while other clients still share it at an adjustable reduced weight (default 30% ≈ 70% less traffic).

**Architecture:** Add `provider_mailbox_id` on `external_integrations`. Extend `MailboxSelector::select` with preferred (soft) vs strict (admin test) modes and soft-weight scoring for mailboxes bound by other active integrations. Wire preferred id from the log’s integration during `EmailDispatchService::deliver`. Expose binding on admin API + Integration edit form. Tune reduction via `MAIL_BOUND_MAILBOX_SHARED_TRAFFIC_PERCENT`.

**Tech Stack:** Laravel 12, PHPUnit, Vue 3 + Vuetify, existing `MailboxSelector` / `ExternalIntegrationController` / `IntegrationFormView.vue`.

**Spec:** `docs/superpowers/specs/2026-10-03-integration-mailbox-binding-design.md`

## Global Constraints

- Soft shared traffic percent: env `MAIL_BOUND_MAILBOX_SHARED_TRAFFIC_PERCENT`, config `mail.bound_mailbox_shared_traffic_percent`, default `30`, clamp 1–100
- Effective weight for shared auto-select: `max(1, (int) round(weight * percent / 100))` when another active integration is bound to that mailbox
- Bound client: prefer mailbox while active + remaining quota; on quota hit / inactive / missing → auto-select
- Admin provider test `from_mailbox_id`: keep **strict** hard-pick (throw if missing/disabled; no soft weight)
- Do not commit unless the user asks

## File map

| File | Responsibility |
|---|---|
| `backend/database/migrations/2026_10_03_180000_add_provider_mailbox_id_to_external_integrations.php` | FK column |
| `backend/config/mail.php` + `backend/.env.example` | Adjustable percent |
| `backend/app/Models/ExternalIntegration.php` | Fillable + `providerMailbox()` |
| `backend/app/Services/MailboxSelector.php` | Preferred path + soft weight |
| `backend/app/Services/EmailDispatchService.php` | Pass preferred id + integration on deliver |
| `backend/app/Http/Requests/.../Store|UpdateExternalIntegrationRequest.php` | Validate mailbox ↔ provider |
| `backend/app/Http/Controllers/.../ExternalIntegrationController.php` | Persist + transform |
| `frontend/src/views/IntegrationFormView.vue` | Mailbox select UI |
| `backend/tests/Feature/IntegrationMailboxBindingTest.php` | New feature tests |

---

### Task 1: Config + migration + model

**Files:**
- Modify: `backend/config/mail.php`
- Modify: `backend/.env.example`
- Create: `backend/database/migrations/2026_10_03_180000_add_provider_mailbox_id_to_external_integrations.php`
- Modify: `backend/app/Models/ExternalIntegration.php`

**Interfaces:**
- Produces: `config('mail.bound_mailbox_shared_traffic_percent')` → int default 30
- Produces: `ExternalIntegration::$fillable` includes `provider_mailbox_id`; `providerMailbox(): BelongsTo`

- [ ] **Step 1: Add config keys** at end of `backend/config/mail.php` (before closing `];`):

```php
    /*
    |--------------------------------------------------------------------------
    | Bound mailbox shared traffic percent
    |--------------------------------------------------------------------------
    |
    | When an active integration is bound to a mailbox, other clients that
    | auto-select among mailboxes treat that mailbox's weight as this percent
    | of its configured weight (default 30 ≈ 70% less shared traffic).
    |
    */

    'bound_mailbox_shared_traffic_percent' => (int) env('MAIL_BOUND_MAILBOX_SHARED_TRAFFIC_PERCENT', 30),
```

Add to `backend/.env.example` near mail attachment settings:

```
# When a client is bound to a mailbox, other clients use this % of that mailbox's weight (30 ≈ 70% reduction)
MAIL_BOUND_MAILBOX_SHARED_TRAFFIC_PERCENT=30
```

- [ ] **Step 2: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_integrations', function (Blueprint $table) {
            $table->foreignId('provider_mailbox_id')
                ->nullable()
                ->after('email_provider_id')
                ->constrained('provider_mailboxes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('external_integrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('provider_mailbox_id');
        });
    }
};
```

- [ ] **Step 3: Model** — add `'provider_mailbox_id'` to `$fillable` and:

```php
public function providerMailbox(): BelongsTo
{
    return $this->belongsTo(ProviderMailbox::class, 'provider_mailbox_id');
}
```

- [ ] **Step 4: Commit only if user asked** — otherwise leave working tree dirty for later tasks.

---

### Task 2: MailboxSelector preferred + soft weight (TDD)

**Files:**
- Create: `backend/tests/Feature/IntegrationMailboxBindingTest.php`
- Modify: `backend/app/Services/MailboxSelector.php`

**Interfaces:**
- Consumes: `config('mail.bound_mailbox_shared_traffic_percent')`, `ExternalIntegration`
- Produces:

```php
public function select(
    EmailProvider $provider,
    ?int $mailboxId = null,
    ?ExternalIntegration $integration = null,
    bool $strictMailbox = false,
): ProviderMailbox
```

- Existing callers: `select($provider)` and `select($provider, $id)` remain valid (`$strictMailbox` default `false`; when admin test passes an id, dispatch must pass `strictMailbox: true`).

- [ ] **Step 1: Write failing tests** in `IntegrationMailboxBindingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\EmailProvider;
use App\Models\ExternalIntegration;
use App\Services\MailboxSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationMailboxBindingTest extends TestCase
{
    use RefreshDatabase;

    private function makeProviderWithMailboxes(): array
    {
        $provider = EmailProvider::factory()->create([
            'from_address' => null,
            'is_active' => true,
        ]);
        $bound = $provider->mailboxes()->create([
            'email' => 'bound@example.com',
            'daily_quota' => 100,
            'hourly_quota' => null,
            'weight' => 10,
            'is_active' => true,
        ]);
        $free = $provider->mailboxes()->create([
            'email' => 'free@example.com',
            'daily_quota' => 100,
            'hourly_quota' => null,
            'weight' => 10,
            'is_active' => true,
        ]);

        return [$provider, $bound, $free];
    }

    private function makeIntegration(?int $providerId, ?int $mailboxId, bool $active = true): ExternalIntegration
    {
        return ExternalIntegration::query()->create([
            'name' => 'Client '.uniqid(),
            'slug' => 'client-'.uniqid(),
            'api_key_hash' => hash('sha256', 'secret'),
            'api_key_prefix' => 'abcd••••',
            'email_provider_id' => $providerId,
            'provider_mailbox_id' => $mailboxId,
            'is_active' => $active,
            'allowed_ips' => [],
        ]);
    }

    public function test_bound_client_prefers_bound_mailbox_when_quota_remains(): void
    {
        [$provider, $bound, $free] = $this->makeProviderWithMailboxes();
        $integration = $this->makeIntegration($provider->id, $bound->id);

        // Make free look "better" on raw weight deficit if preference were ignored
        EmailLog::query()->create([
            'email_provider_id' => $provider->id,
            'to' => 'u@example.com',
            'from_address' => $bound->email,
            'subject' => 'x',
            'status' => 'sent',
            'driver' => $provider->driver->value,
            'meta' => [],
        ]);

        $chosen = app(MailboxSelector::class)->select(
            $provider,
            $bound->id,
            $integration,
            strictMailbox: false,
        );
        $this->assertSame($bound->id, $chosen->id);
    }

    public function test_bound_client_falls_back_when_preferred_quota_hit(): void
    {
        [$provider, $bound, $free] = $this->makeProviderWithMailboxes();
        $bound->update(['daily_quota' => 1]);
        $integration = $this->makeIntegration($provider->id, $bound->id);

        EmailLog::query()->create([
            'email_provider_id' => $provider->id,
            'to' => 'u@example.com',
            'from_address' => $bound->email,
            'subject' => 'x',
            'status' => 'sent',
            'driver' => $provider->driver->value,
            'meta' => [],
        ]);

        $chosen = app(MailboxSelector::class)->select(
            $provider,
            $bound->id,
            $integration,
            strictMailbox: false,
        );
        $this->assertSame($free->id, $chosen->id);
    }

    public function test_unbound_client_downshifts_weight_on_mailbox_bound_by_other(): void
    {
        config(['mail.bound_mailbox_shared_traffic_percent' => 30]);
        [$provider, $bound, $free] = $this->makeProviderWithMailboxes();
        $this->makeIntegration($provider->id, $bound->id); // other client binds bound@
        $unbound = $this->makeIntegration($provider->id, null);

        // Equal sent counts → without soft weight either could win by id;
        // with soft weight: bound effective weight = max(1, round(10*0.3))=3
        // scores both 0 → still lowest id. Seed one send on free so scores:
        // bound: 0/3=0, free: 1/10=0.1 → pick bound WITHOUT soft weight;
        // WITH soft weight same. Need: send on bound so unbound prefers free.
        // bound score 1/3≈0.33, free score 0/10=0 → pick free.
        EmailLog::query()->create([
            'email_provider_id' => $provider->id,
            'to' => 'u@example.com',
            'from_address' => $bound->email,
            'subject' => 'x',
            'status' => 'sent',
            'driver' => $provider->driver->value,
            'meta' => [],
        ]);

        $chosen = app(MailboxSelector::class)->select($provider, null, $unbound);
        $this->assertSame($free->id, $chosen->id);

        // Without binding, same history: scores 1/10 vs 0/10 → still free.
        // Prove soft weight matters: equalize sends, percent 30 vs 100.
        EmailLog::query()->create([
            'email_provider_id' => $provider->id,
            'to' => 'u@example.com',
            'from_address' => $free->email,
            'subject' => 'x',
            'status' => 'sent',
            'driver' => $provider->driver->value,
            'meta' => [],
        ]);
        // sent: bound=1, free=1. Soft: bound eff=3 score=0.333; free=10 score=0.1 → free
        $chosenSoft = app(MailboxSelector::class)->select($provider, null, $unbound);
        $this->assertSame($free->id, $chosenSoft->id);

        config(['mail.bound_mailbox_shared_traffic_percent' => 100]);
        // No downshift: both score 1/10; tie → lowest id = bound
        $chosenFull = app(MailboxSelector::class)->select($provider, null, $unbound);
        $this->assertSame($bound->id, $chosenFull->id);
    }

    public function test_strict_mailbox_ignores_quota_and_soft_weight(): void
    {
        [$provider, $bound] = $this->makeProviderWithMailboxes();
        $bound->update(['daily_quota' => 1]);
        EmailLog::query()->create([
            'email_provider_id' => $provider->id,
            'to' => 'u@example.com',
            'from_address' => $bound->email,
            'subject' => 'x',
            'status' => 'sent',
            'driver' => $provider->driver->value,
            'meta' => [],
        ]);

        $chosen = app(MailboxSelector::class)->select(
            $provider,
            $bound->id,
            null,
            strictMailbox: true,
        );
        $this->assertSame($bound->id, $chosen->id);
    }
}
```

- [ ] **Step 2: Run tests — expect FAIL** (signature / soft weight missing)

```bash
cd /Users/user/Documents/email_server/docker && docker compose run --rm --no-deps --entrypoint bash app -lc \
  'cd /var/www/backend && php artisan migrate --force && vendor/bin/phpunit --filter=IntegrationMailboxBindingTest'
```

If Docker unavailable, run locally with the project’s usual PHPUnit entrypoint. Expected: FAIL (unknown named arg / wrong selection).

- [ ] **Step 3: Implement `MailboxSelector::select`**

Replace `select` with:

```php
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
    // existing hard-pick body (throw if missing/inactive)
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
    if (($usage['remaining_1h'] ?? 1) !== null && (int) ($usage['remaining_1h'] ?? 1) <= 0) {
        return null;
    }

    return $mailbox;
}

private function selectWeighted(EmailProvider $provider, ?ExternalIntegration $integration): ProviderMailbox
{
    // existing empty-active check…
    $boundIds = $this->mailboxIdsBoundByOthers($provider->id, $integration?->id);
    $percent = $this->sharedTrafficPercent();
    $factor = $percent / 100.0;

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
        ->sortBy([['score', 'asc'], ['id', 'asc']])
        ->values();
    // empty → MailboxQuotaExhaustedException; else findOrFail first id
}

/**
 * @return array<int, true>
 */
private function mailboxIdsBoundByOthers(int $providerId, ?int $exceptIntegrationId): array
{
    $q = ExternalIntegration::query()
        ->where('is_active', true)
        ->whereNotNull('provider_mailbox_id')
        ->whereHas('providerMailbox', fn ($q) => $q->where('email_provider_id', $providerId));

    if ($exceptIntegrationId !== null) {
        $q->where('id', '!=', $exceptIntegrationId);
    }

    return $q->pluck('provider_mailbox_id')
        ->mapWithKeys(fn ($id) => [(int) $id => true])
        ->all();
}

private function sharedTrafficPercent(): int
{
    $percent = (int) config('mail.bound_mailbox_shared_traffic_percent', 30);

    return max(1, min(100, $percent));
}
```

Import `ExternalIntegration`. Keep `usageFor` unchanged.

- [ ] **Step 4: Run tests — expect PASS**

```bash
cd /Users/user/Documents/email_server/docker && docker compose run --rm --no-deps --entrypoint bash app -lc \
  'cd /var/www/backend && vendor/bin/phpunit --filter=IntegrationMailboxBindingTest'
```

- [ ] **Step 5: Update admin strict call site** in `EmailDispatchService::attemptSendWithProvider`:

```php
$mailbox = $this->mailboxSelector->select(
    $provider,
    $explicitMailboxId,
    $log->externalIntegration,
    strictMailbox: $explicitMailboxId !== null,
);
```

And in `deliver()` / `transmit` path: when `$explicitMailboxId === null`, pass preferred from integration:

Change `deliver()` transmit call:

```php
explicitMailboxId: $log->externalIntegration?->provider_mailbox_id,
```

Wait — that would make preferred id look like strict if we use `strictMailbox: $explicitMailboxId !== null`. **Do not** pass preferred as `explicitMailboxId` for that reason.

Instead add a separate parameter to `transmit` / `attemptSendWithProvider`:

```php
?int $preferredMailboxId = null,
```

In `deliver()`:

```php
preferredMailboxId: $log->externalIntegration?->provider_mailbox_id,
```

In `attemptSendWithProvider`:

```php
$mailbox = $this->mailboxSelector->select(
    $provider,
    $explicitMailboxId ?? $preferredMailboxId,
    $log->relationLoaded('externalIntegration')
        ? $log->externalIntegration
        : $log->externalIntegration()->first(),
    strictMailbox: $explicitMailboxId !== null,
);
```

On cross-provider fallback keep `preferredMailboxId: null` and `explicitMailboxId: null`.

Ensure `deliver` already calls `$log->loadMissing('externalIntegration')` before transmit (it does).

- [ ] **Step 6: Run existing mailbox tests**

```bash
cd /Users/user/Documents/email_server/docker && docker compose run --rm --no-deps --entrypoint bash app -lc \
  'cd /var/www/backend && vendor/bin/phpunit --filter=ProviderMailboxQuotaTest'
```

Expected: PASS (positional `select($provider, $id)` for tests uses `strictMailbox: false` by default — **update** any admin-test-style unit that needs strict, OR update `ProviderMailboxQuotaTest` explicit-id cases to pass `strictMailbox: true` if they assert throw-on-disabled). Grep callers:

```bash
rg "mailboxSelector->select|->select\\(\\$provider" backend
```

Update each:
- Auto: `select($provider)` or `select($provider, null, $integration)`
- Strict admin: `select($provider, $id, null, true)`

---

### Task 3: Admin API validation + transform

**Files:**
- Modify: `backend/app/Http/Requests/Api/V1/Admin/StoreExternalIntegrationRequest.php`
- Modify: `backend/app/Http/Requests/Api/V1/Admin/UpdateExternalIntegrationRequest.php`
- Modify: `backend/app/Http/Controllers/Api/V1/Admin/ExternalIntegrationController.php`
- Modify: `backend/tests/Feature/IntegrationMailboxBindingTest.php` (add API tests)

**Interfaces:**
- Produces: JSON field `provider_mailbox_id` + nested `provider_mailbox: {id,email}|null`
- Validation: mailbox exists and `provider_mailboxes.email_provider_id` equals request `email_provider_id` (or existing integration provider on update when provider omitted)

- [ ] **Step 1: Failing API tests**

```php
public function test_admin_can_bind_mailbox_on_update(): void
{
    [$provider, $bound] = $this->makeProviderWithMailboxes();
    $integration = $this->makeIntegration($provider->id, null);
    $token = /* admin sanctum token same as ProviderMailboxQuotaTest */;

    $this->withToken($token)
        ->putJson("/api/v1/admin/external-integrations/{$integration->id}", [
            'email_provider_id' => $provider->id,
            'provider_mailbox_id' => $bound->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.provider_mailbox_id', $bound->id)
        ->assertJsonPath('data.provider_mailbox.email', 'bound@example.com');
}

public function test_admin_rejects_mailbox_from_other_provider(): void
{
    [$provider, $bound] = $this->makeProviderWithMailboxes();
    $other = EmailProvider::factory()->create(['from_address' => null]);
    $foreign = $other->mailboxes()->create([
        'email' => 'foreign@example.com', 'daily_quota' => 10, 'is_active' => true,
    ]);
    $integration = $this->makeIntegration($provider->id, null);
    $token = /* admin token */;

    $this->withToken($token)
        ->putJson("/api/v1/admin/external-integrations/{$integration->id}", [
            'email_provider_id' => $provider->id,
            'provider_mailbox_id' => $foreign->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['provider_mailbox_id']);
}
```

- [ ] **Step 2: Run — expect FAIL** (422 missing rule / column not in transform)

- [ ] **Step 3: Validation rules**

Shared rule pattern (both Store and Update):

```php
'provider_mailbox_id' => [
    'nullable',
    'integer',
    Rule::exists('provider_mailboxes', 'id')->where(function ($q) {
        $providerId = $this->input('email_provider_id');
        if ($providerId === null && $this->route('external_integration')) {
            $providerId = $this->route('external_integration')->email_provider_id;
        }
        if ($providerId) {
            $q->where('email_provider_id', $providerId);
        } else {
            $q->whereRaw('1 = 0'); // mailbox requires a provider
        }
    }),
],
```

Add `use Illuminate\Validation\Rule;` to Store request.

Also: if `email_provider_id` is explicitly null on update, force `provider_mailbox_id` null in controller before update:

```php
if (array_key_exists('email_provider_id', $data) && $data['email_provider_id'] === null) {
    $data['provider_mailbox_id'] = null;
}
```

If provider changes and mailbox omitted, clear mailbox when it would be invalid:

```php
if (array_key_exists('email_provider_id', $data) && ! array_key_exists('provider_mailbox_id', $data)) {
    $newProviderId = $data['email_provider_id'];
    $currentMailboxId = $externalIntegration->provider_mailbox_id;
    if ($currentMailboxId && $newProviderId) {
        $ok = ProviderMailbox::query()
            ->whereKey($currentMailboxId)
            ->where('email_provider_id', $newProviderId)
            ->exists();
        if (! $ok) {
            $data['provider_mailbox_id'] = null;
        }
    } elseif ($currentMailboxId && ! $newProviderId) {
        $data['provider_mailbox_id'] = null;
    }
}
```

- [ ] **Step 4: Controller** — include `provider_mailbox_id` on create; eager-load `providerMailbox:id,email`; transform:

```php
'provider_mailbox_id' => $integration->provider_mailbox_id,
'provider_mailbox' => $integration->providerMailbox ? [
    'id' => $integration->providerMailbox->id,
    'email' => $integration->providerMailbox->email,
] : null,
```

Load relation on index/show/store/update: `->with(['emailProvider:id,name,driver', 'providerMailbox:id,email'])`.

- [ ] **Step 5: Run API + selector tests — expect PASS**

---

### Task 4: Frontend integration form

**Files:**
- Modify: `frontend/src/views/IntegrationFormView.vue`

**Interfaces:**
- Consumes: `GET /admin/email-providers/:id` → `data.mailboxes[]` with `id`, `email`, `is_active`
- Produces: PUT/POST `provider_mailbox_id`

- [ ] **Step 1: Extend form state**

```ts
type MailboxOption = { id: number; email: string; is_active: boolean }

const mailboxes = ref<MailboxOption[]>([])
// form:
provider_mailbox_id: null as number | null,
```

- [ ] **Step 2: Load mailboxes when provider changes**

```ts
async function loadMailboxes(providerId: number | null) {
  mailboxes.value = []
  if (!providerId) return
  const res = await api.get(`/admin/email-providers/${providerId}`)
  mailboxes.value = (res.data.data.mailboxes ?? []).filter((m: MailboxOption) => m.id != null)
}

watch(
  () => form.value.email_provider_id,
  async (providerId, prev) => {
    await loadMailboxes(providerId)
    if (prev !== undefined && providerId !== prev) {
      const stillValid = mailboxes.value.some((m) => m.id === form.value.provider_mailbox_id)
      if (!stillValid) form.value.provider_mailbox_id = null
    }
  },
)
```

In `loadIntegration`, set `provider_mailbox_id: i.provider_mailbox_id ?? null` then `await loadMailboxes(form.value.email_provider_id)`.

- [ ] **Step 3: Template** — after Email provider select:

```vue
<FormField label="Preferred from mailbox">
  <v-select
    v-model="form.provider_mailbox_id"
    :items="mailboxes"
    item-title="email"
    item-value="id"
    variant="outlined"
    hide-details
    clearable
    :disabled="!form.email_provider_id"
    :placeholder="form.email_provider_id ? 'Any mailbox (weighted)' : 'Select a provider first'"
  />
  <div class="text-caption text-medium-emphasis mt-1">
    This client uses the selected mailbox when quota remains. Other clients can still use it at a reduced share.
  </div>
</FormField>
```

- [ ] **Step 4: Include in save payload**

```ts
provider_mailbox_id: form.value.provider_mailbox_id,
```

- [ ] **Step 5: Manual smoke** — open `/integrations/1/edit`, pick provider + mailbox, save, reload; confirm values stick. (No frontend unit test required.)

---

### Task 5: Dispatch deliver wiring regression test

**Files:**
- Modify: `backend/tests/Feature/IntegrationMailboxBindingTest.php`
- Modify: `backend/app/Services/EmailDispatchService.php` (if not finished in Task 2)

- [ ] **Step 1: Feature test** that queues/delivers with mocked mailer and asserts `from_address` is the bound mailbox (mirror patterns in `ProviderMailboxQuotaTest` for mocked SMTP/send).

Minimal approach if full deliver is heavy: unit-level already covered in Task 2; add one integration test that calls `MailboxSelector` through `EmailDispatchService` only if an existing send mock is easy to reuse from `ProviderMailboxQuotaTest::test_*fallback*`. Prefer reusing Mockery on `PhpMailerSmtpMailer` / Mail facade as in that file.

- [ ] **Step 2: Run full relevant suite**

```bash
cd /Users/user/Documents/email_server/docker && docker compose run --rm --no-deps --entrypoint bash app -lc \
  'cd /var/www/backend && vendor/bin/phpunit --filter="IntegrationMailboxBindingTest|ProviderMailboxQuotaTest|ScopedExternalIntegrationTest"'
```

Expected: PASS

- [ ] **Step 3: Commit when user asks** with message:

```
Bind integrations to a preferred mailbox with adjustable shared weight.

```

---

## Spec coverage checklist

| Spec requirement | Task |
|---|---|
| `provider_mailbox_id` FK | Task 1 |
| Adjustable `MAIL_BOUND_MAILBOX_SHARED_TRAFFIC_PERCENT` | Task 1 |
| Prefer bound mailbox while quota remains | Task 2 |
| Fall back when quota hit / inactive | Task 2 |
| Soft weight × percent for other clients | Task 2 |
| Strict admin test unchanged | Task 2 |
| Admin validate mailbox belongs to provider | Task 3 |
| Transform exposes binding | Task 3 |
| Integration edit UI | Task 4 |
| Deliver uses integration binding | Task 2 + 5 |
| Multiple integrations same mailbox | Task 2 (bound-by-others set) |
| Provider/OTP fallback unchanged | No code change (fallback still null preferred) |
