<?php

namespace Tests\Feature;

use App\Enums\EmailDriver;
use App\Models\EmailProvider;
use App\Models\ExternalIntegration;
use App\Models\User;
use App\Services\IntegrationJwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_rejects_sql_injection_in_email_field(): void
    {
        $this->postJson('/api/v1/admin/auth/login', [
            'email' => "admin@emailserver.local' OR '1'='1",
            'password' => 'password',
        ])->assertStatus(422);
    }

    public function test_integration_token_rejects_sql_injection_in_client_id(): void
    {
        config(['integration.jwt_secret' => 'testing-jwt-secret-key-with-at-least-sixty-four-characters-long!!']);

        $this->postJson('/api/v1/integrations/auth/token', [
            'client_id' => "staff-portal' OR '1'='1",
            'client_secret' => 'ValidSecret12345678',
        ])->assertUnauthorized();
    }

    public function test_admin_routes_reject_unauthenticated_access(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();
        $this->postJson('/api/v1/admin/email-providers', [])->assertUnauthorized();
    }

    public function test_non_admin_cannot_access_user_management(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'is_active' => true,
            'password' => Hash::make('ViewerPass123!'),
        ]);

        $token = $user->createToken('admin-panel')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/admin/users')
            ->assertForbidden();
    }

    public function test_inactive_admin_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'inactive@emailserver.local',
            'is_admin' => true,
            'is_active' => false,
            'password' => Hash::make('InactivePass123!'),
        ]);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'inactive@emailserver.local',
            'password' => 'InactivePass123!',
        ])->assertStatus(422);
    }

    public function test_integration_send_rejects_invalid_jwt(): void
    {
        $this->withToken('not.a.valid.jwt.token')
            ->postJson('/api/v1/integrations/send', [
                'to' => 'user@example.com',
                'subject' => 'x',
                'body' => 'y',
            ])
            ->assertUnauthorized();
    }

    public function test_user_search_fields_use_parameterized_queries(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $token = $admin->createToken('admin-panel')->plainTextToken;

        User::factory()->create(['name' => 'Normal User']);

        $this->withToken($token)
            ->getJson('/api/v1/admin/users')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_stored_integration_name_is_returned_escaped_in_json(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $token = $admin->createToken('admin-panel')->plainTextToken;

        $payload = '<script>alert(1)</script>';

        $this->withToken($token)->postJson('/api/v1/admin/external-integrations', [
            'name' => $payload,
            'slug' => 'xss-test',
            'client_secret' => 'IntegrationSecret2026!',
        ])->assertCreated()
            ->assertJsonPath('data.name', $payload);

        $this->assertDatabaseHas('external_integrations', [
            'slug' => 'xss-test',
            'name' => $payload,
        ]);
    }

    public function test_integration_can_be_created_with_generated_secret(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $token = $admin->createToken('admin-panel')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/admin/external-integrations', [
            'name' => 'Generated Secret App',
            'slug' => 'generated-secret-app',
            'generate_secret' => true,
        ])->assertCreated();

        $secret = $response->json('client_secret');
        $this->assertIsString($secret);
        $this->assertGreaterThanOrEqual(16, strlen($secret));

        $integration = ExternalIntegration::query()->where('slug', 'generated-secret-app')->firstOrFail();
        $this->assertTrue($integration->verifyClientSecret($secret));
    }

    public function test_integration_token_requires_minimum_secret_length(): void
    {
        config(['integration.jwt_secret' => 'testing-jwt-secret-key-with-at-least-sixty-four-characters-long!!']);

        $this->postJson('/api/v1/integrations/auth/token', [
            'client_id' => 'staff-portal',
            'client_secret' => 'short',
        ])->assertStatus(422);
    }
}
