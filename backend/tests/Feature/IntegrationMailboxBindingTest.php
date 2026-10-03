<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\EmailProvider;
use App\Models\ExternalIntegration;
use App\Models\User;
use App\Services\MailboxSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class IntegrationMailboxBindingTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
            'password' => Hash::make('Secret123!'),
        ]);

        return $admin->createToken('admin-panel')->plainTextToken;
    }

    /**
     * @return array{0: EmailProvider, 1: \App\Models\ProviderMailbox, 2: \App\Models\ProviderMailbox}
     */
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
        [$provider, $bound] = $this->makeProviderWithMailboxes();
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
        $this->makeIntegration($provider->id, $bound->id);
        $unbound = $this->makeIntegration($provider->id, null);

        EmailLog::query()->create([
            'email_provider_id' => $provider->id,
            'to' => 'u@example.com',
            'from_address' => $bound->email,
            'subject' => 'x',
            'status' => 'sent',
            'driver' => $provider->driver->value,
            'meta' => [],
        ]);
        EmailLog::query()->create([
            'email_provider_id' => $provider->id,
            'to' => 'u@example.com',
            'from_address' => $free->email,
            'subject' => 'x',
            'status' => 'sent',
            'driver' => $provider->driver->value,
            'meta' => [],
        ]);

        // sent equal: soft bound score 1/3≈0.33, free 1/10=0.1 → free
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

    public function test_admin_can_bind_mailbox_on_update(): void
    {
        [$provider, $bound] = $this->makeProviderWithMailboxes();
        $integration = $this->makeIntegration($provider->id, null);
        $token = $this->adminToken();

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
        [$provider] = $this->makeProviderWithMailboxes();
        $other = EmailProvider::factory()->create(['from_address' => null]);
        $foreign = $other->mailboxes()->create([
            'email' => 'foreign@example.com',
            'daily_quota' => 10,
            'is_active' => true,
        ]);
        $integration = $this->makeIntegration($provider->id, null);
        $token = $this->adminToken();

        $this->withToken($token)
            ->putJson("/api/v1/admin/external-integrations/{$integration->id}", [
                'email_provider_id' => $provider->id,
                'provider_mailbox_id' => $foreign->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['provider_mailbox_id']);
    }
}
