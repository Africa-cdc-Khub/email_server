<?php

namespace Tests\Feature;

use App\Enums\EmailDriver;
use App\Enums\UserApprovalStatus;
use App\Jobs\SendEmailJob;
use App\Models\BrandingSetting;
use App\Models\EmailLog;
use App\Models\EmailProvider;
use App\Models\ExternalIntegration;
use App\Models\User;
use App\Services\MigrationExportService;
use App\Services\MigrationPackageCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
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

    public function test_admin_can_export_encrypted_migration_package_with_one_time_key(): void
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

        EmailLog::query()->create([
            'email_provider_id' => $provider->id,
            'external_integration_id' => $client->id,
            'to' => 'pending@example.com',
            'subject' => 'Your sign-in code: 123456',
            'status' => 'pending',
            'driver' => $provider->driver->value,
            'meta' => ['body' => '<p>code</p>', 'is_html' => true],
        ]);
        EmailLog::query()->create([
            'email_provider_id' => $provider->id,
            'to' => 'sent@example.com',
            'subject' => 'Already sent',
            'status' => 'sent',
            'driver' => $provider->driver->value,
            'meta' => ['body' => '<p>done</p>', 'is_html' => true],
        ]);

        $res = $this->withToken($this->adminToken())
            ->getJson('/api/v1/admin/migration/export')
            ->assertOk()
            ->assertJsonStructure([
                'encryption_key',
                'filename',
                'package' => ['meta', 'nonce', 'tag', 'ciphertext'],
                'message',
            ]);

        $key = $res->json('encryption_key');
        $this->assertIsString($key);
        $this->assertGreaterThanOrEqual(40, strlen($key));

        $package = $res->json('package');
        $this->assertSame(MigrationExportService::SCHEMA_VERSION, $package['meta']['schema_version']);
        $this->assertTrue($package['meta']['encrypted']);
        $this->assertSame(MigrationPackageCipher::CIPHER, $package['meta']['cipher']);
        $this->assertStringNotContainsString('secret-pass', json_encode($package));
        $this->assertArrayNotHasKey('email_providers', $package);

        $cipher = app(MigrationPackageCipher::class);
        $plaintext = $cipher->decrypt(
            $package['ciphertext'],
            $package['nonce'],
            $package['tag'],
            $cipher->decodeKey($key)
        );
        $inner = json_decode($plaintext, true);
        $this->assertSame('secret-pass', $inner['email_providers'][0]['config']['password']);
        $this->assertSame('partner@example.com', collect($inner['users'])->firstWhere('email', 'partner@example.com')['email']);
        $this->assertSame('Migrated App', $inner['branding']['app_name']);
        $this->assertArrayHasKey('pending_email_logs', $inner);
        $this->assertCount(1, $inner['pending_email_logs']);
        $this->assertSame('pending@example.com', $inner['pending_email_logs'][0]['to']);
        $this->assertSame('smtp-main', $inner['pending_email_logs'][0]['provider_slug']);
        $this->assertSame('partner-app', $inner['pending_email_logs'][0]['client_slug']);
    }

    public function test_non_admin_cannot_export(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->withToken($user->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/migration/export')
            ->assertForbidden();
    }

    public function test_import_requires_key_and_upserts_records(): void
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

        $newSecret = 'new-client-secret-value-99';
        $client = ExternalIntegration::query()->create([
            'name' => 'Partner App',
            'slug' => 'partner-app',
            'api_key_hash' => ExternalIntegration::hashClientSecret('old-client-secret-value'),
            'api_key_prefix' => 'old…',
            'email_provider_id' => $provider->id,
            'is_active' => false,
        ]);

        $payload = [
            'meta' => [
                'schema_version' => MigrationExportService::PAYLOAD_VERSION,
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
            'pending_email_logs' => [[
                'to' => 'waiting@example.com',
                'from_address' => null,
                'subject' => 'Your sign-in code: 424242',
                'driver' => EmailDriver::Smtp->value,
                'provider_slug' => 'smtp-main',
                'client_slug' => 'partner-app',
                'meta' => ['body' => '<p>hello pending</p>', 'is_html' => true],
                'attachments' => [],
                'created_at' => now()->toIso8601String(),
            ], [
                'to' => 'should-skip@example.com',
                'subject' => 'Sent should be ignored',
                'status' => 'sent',
                'driver' => EmailDriver::Smtp->value,
                'provider_slug' => 'smtp-main',
                'meta' => ['body' => '<p>nope</p>', 'is_html' => true],
                'attachments' => [],
            ]],
        ];

        Queue::fake();

        $cipher = app(MigrationPackageCipher::class);
        $key = $cipher->generateKey();
        $sealed = $cipher->encrypt(json_encode($payload, JSON_THROW_ON_ERROR), $key['key_raw']);
        $envelope = [
            'meta' => [
                'schema_version' => MigrationExportService::SCHEMA_VERSION,
                'encrypted' => true,
                'cipher' => MigrationPackageCipher::CIPHER,
                'kdf' => 'none',
                'key_bytes' => MigrationPackageCipher::KEY_BYTES,
            ],
            'nonce' => $sealed['nonce'],
            'tag' => $sealed['tag'],
            'ciphertext' => $sealed['ciphertext'],
        ];

        $file = UploadedFile::fake()->createWithContent(
            'migration.json',
            json_encode($envelope, JSON_THROW_ON_ERROR)
        );

        $this->withToken($this->adminToken())
            ->post('/api/v1/admin/migration/import', ['file' => $file])
            ->assertStatus(422);

        $this->withToken($this->adminToken())
            ->post('/api/v1/admin/migration/import', [
                'file' => $file,
                'encryption_key' => 'not-a-valid-key',
            ])
            ->assertStatus(422);

        $this->withToken($this->adminToken())
            ->post('/api/v1/admin/migration/import', [
                'file' => UploadedFile::fake()->createWithContent(
                    'migration.json',
                    json_encode($envelope, JSON_THROW_ON_ERROR)
                ),
                'encryption_key' => $key['key_encoded'],
            ])
            ->assertOk()
            ->assertJsonPath('data.users.created', 1)
            ->assertJsonPath('data.clients.updated', 1)
            ->assertJsonPath('data.providers.updated', 1)
            ->assertJsonPath('data.branding_updated', true)
            ->assertJsonPath('data.pending_email_logs.created', 1)
            ->assertJsonPath('data.pending_email_logs.queued', 1)
            ->assertJsonPath('data.pending_email_logs.skipped', 1);

        $this->assertDatabaseHas('users', ['email' => 'new-partner@example.com']);
        $this->assertDatabaseHas('email_logs', [
            'to' => 'waiting@example.com',
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('email_logs', [
            'to' => 'should-skip@example.com',
        ]);
        Queue::assertPushed(SendEmailJob::class, 1);
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

    public function test_import_rejects_wrong_encryption_key(): void
    {
        $cipher = app(MigrationPackageCipher::class);
        $right = $cipher->generateKey();
        $wrong = $cipher->generateKey();
        $sealed = $cipher->encrypt('{"meta":{"schema_version":1}}', $right['key_raw']);
        $envelope = [
            'meta' => [
                'schema_version' => MigrationExportService::SCHEMA_VERSION,
                'encrypted' => true,
                'cipher' => MigrationPackageCipher::CIPHER,
            ],
            'nonce' => $sealed['nonce'],
            'tag' => $sealed['tag'],
            'ciphertext' => $sealed['ciphertext'],
        ];

        $file = UploadedFile::fake()->createWithContent(
            'migration.json',
            json_encode($envelope, JSON_THROW_ON_ERROR)
        );

        $this->withToken($this->adminToken())
            ->post('/api/v1/admin/migration/import', [
                'file' => $file,
                'encryption_key' => $wrong['key_encoded'],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Could not decrypt migration package. Check the encryption key and file integrity.']);
    }
}
