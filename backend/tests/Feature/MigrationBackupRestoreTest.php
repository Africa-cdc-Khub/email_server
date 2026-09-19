<?php

namespace Tests\Feature;

use App\Enums\EmailDriver;
use App\Enums\UserApprovalStatus;
use App\Models\BrandingSetting;
use App\Models\EmailProvider;
use App\Models\ExternalIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MigrationBackupRestoreTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);

        return $admin->createToken('admin-panel')->plainTextToken;
    }

    public function test_admin_can_export_migration_package(): void
    {
        Storage::fake('public');

        $provider = EmailProvider::query()->create([
            'name' => 'SMTP',
            'slug' => 'smtp-main',
            'driver' => EmailDriver::Smtp,
            'config' => ['host' => 'smtp.example.com', 'password' => 'secret-pass'],
            'from_address' => 'noreply@example.com',
            'is_default' => true,
            'is_active' => true,
        ]);

        $partner = User::factory()->create([
            'email' => 'partner@example.com',
            'is_admin' => false,
            'approval_status' => UserApprovalStatus::Approved,
        ]);

        $client = ExternalIntegration::query()->create([
            'name' => 'Partner App',
            'slug' => 'partner-app',
            'api_key_hash' => ExternalIntegration::hashClientSecret('client-secret-value-99'),
            'api_key_prefix' => 'cli…',
            'email_provider_id' => $provider->id,
            'is_active' => true,
        ]);
        $partner->externalIntegrations()->attach($client->id);

        BrandingSetting::current()->update([
            'app_name' => 'Migrated App',
            'primary_color' => '#112233',
        ]);

        $res = $this->withToken($this->adminToken())
            ->get('/api/v1/admin/migration/export')
            ->assertOk();

        $this->assertStringContainsString('attachment', (string) $res->headers->get('Content-Disposition'));
        $json = $res->json();
        $this->assertSame(1, $json['meta']['schema_version']);
        $this->assertSame('secret-pass', $json['email_providers'][0]['config']['password']);
        $this->assertSame(
            'partner@example.com',
            collect($json['users'])->firstWhere('email', 'partner@example.com')['email']
        );
        $this->assertSame('partner-app', $json['external_integrations'][0]['slug']);
        $this->assertSame('smtp-main', $json['external_integrations'][0]['email_provider_slug']);
        $this->assertTrue(collect($json['user_client_links'])->contains(
            fn ($l) => $l['user_email'] === 'partner@example.com' && $l['client_slug'] === 'partner-app'
        ));
        $this->assertSame('Migrated App', $json['branding']['app_name']);
    }

    public function test_non_admin_cannot_export(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->withToken($user->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/migration/export')
            ->assertForbidden();
    }

    public function test_import_creates_missing_users_and_updates_existing_clients(): void
    {
        Storage::fake('public');

        $provider = EmailProvider::query()->create([
            'name' => 'SMTP',
            'slug' => 'smtp-main',
            'driver' => EmailDriver::Smtp,
            'config' => ['host' => 'old.example.com', 'password' => 'old-pass'],
            'from_address' => 'old@example.com',
            'is_default' => true,
            'is_active' => true,
        ]);

        $oldSecret = 'old-client-secret-value';
        $newSecret = 'new-client-secret-value-99';
        $client = ExternalIntegration::query()->create([
            'name' => 'Partner App',
            'slug' => 'partner-app',
            'api_key_hash' => ExternalIntegration::hashClientSecret($oldSecret),
            'api_key_prefix' => 'old…',
            'email_provider_id' => $provider->id,
            'is_active' => false,
        ]);

        $package = [
            'meta' => [
                'schema_version' => 1,
                'exported_at' => now()->toIso8601String(),
                'app_name' => 'Email Server',
                'source_app_url' => 'https://example.test',
            ],
            'email_providers' => [[
                'name' => 'SMTP',
                'slug' => 'smtp-main',
                'driver' => EmailDriver::Smtp->value,
                'config' => ['host' => 'smtp.example.com', 'password' => 'new-pass'],
                'from_address' => 'noreply@example.com',
                'from_name' => null,
                'is_default' => true,
                'is_active' => true,
                'priority' => 50,
                'description' => null,
            ]],
            'users' => [[
                'name' => 'New Partner',
                'email' => 'new-partner@example.com',
                'password_hash' => Hash::make('SecurePass123!'),
                'phone' => null,
                'organisation' => 'Org',
                'registration_source' => 'public',
                'is_admin' => false,
                'is_active' => true,
                'approval_status' => 'approved',
                'approved_at' => now()->toIso8601String(),
                'approved_by_email' => null,
                'created_by_email' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
                'email_verified_at' => null,
                'two_factor_email_enabled' => false,
                'two_factor_totp_enabled' => false,
                'totp_required' => false,
                'two_factor_totp_secret' => null,
                'two_factor_totp_recovery_codes' => null,
            ]],
            'external_integrations' => [[
                'name' => 'Partner App',
                'slug' => 'partner-app',
                'api_key_hash' => ExternalIntegration::hashClientSecret($newSecret),
                'api_key_prefix' => 'new…',
                'email_provider_slug' => 'smtp-main',
                'allowed_ips' => [],
                'settings' => [],
                'is_active' => true,
                'description' => 'Updated',
            ]],
            'user_client_links' => [[
                'user_email' => 'new-partner@example.com',
                'client_slug' => 'partner-app',
            ]],
            'branding' => [
                'app_name' => 'Restored Brand',
                'tagline' => null,
                'admin_logo_inverse' => false,
                'admin_logo_size_percent' => 100,
                'primary_color' => '#abcdef',
                'secondary_color' => '#c9a227',
                'support_email' => null,
                'logo' => null,
                'logo_dark' => null,
                'favicon' => null,
            ],
        ];

        $file = UploadedFile::fake()->createWithContent(
            'migration.json',
            json_encode($package, JSON_THROW_ON_ERROR)
        );

        $this->withToken($this->adminToken())
            ->post('/api/v1/admin/migration/import', ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.users.created', 1)
            ->assertJsonPath('data.clients.updated', 1)
            ->assertJsonPath('data.providers.updated', 1)
            ->assertJsonPath('data.branding_updated', true);

        $this->assertDatabaseHas('users', ['email' => 'new-partner@example.com']);
        $client->refresh();
        $this->assertSame(ExternalIntegration::hashClientSecret($newSecret), $client->api_key_hash);
        $this->assertTrue($client->is_active);
        $this->assertSame('new-pass', $provider->fresh()->safeConfig()['password']);
        $this->assertSame('Restored Brand', BrandingSetting::current()->fresh()->app_name);

        $this->postJson('/api/v1/integrations/auth/token', [
            'client_id' => 'partner-app',
            'client_secret' => $newSecret,
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_import_rejects_invalid_schema_version(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'migration.json',
            json_encode(['meta' => ['schema_version' => 99]], JSON_THROW_ON_ERROR)
        );

        $this->withToken($this->adminToken())
            ->post('/api/v1/admin/migration/import', ['file' => $file])
            ->assertStatus(422);
    }
}
