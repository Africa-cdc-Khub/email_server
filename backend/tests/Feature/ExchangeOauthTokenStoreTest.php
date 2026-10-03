<?php

namespace Tests\Feature;

use AgabaandreOffice365\ExchangeEmailService\ExchangeOAuth;
use App\Models\ExchangeOauthToken;
use App\Services\ExchangeOauthTokenStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ExchangeOauthTokenStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_tokens_are_stored_encrypted_in_database_not_plaintext_file(): void
    {
        $store = app(ExchangeOauthTokenStore::class);
        $legacy = $store->legacyFilePath();
        File::ensureDirectoryExists(dirname($legacy));
        if (is_file($legacy)) {
            File::delete($legacy);
        }

        $store->put(
            'azure-client-123',
            'access-secret-token',
            'refresh-secret-token',
            time() + 3600,
            ExchangeOAuth::AUTH_CLIENT_CREDENTIALS,
        );

        $this->assertDatabaseHas('exchange_oauth_tokens', [
            'client_id' => 'azure-client-123',
            'auth_method' => ExchangeOAuth::AUTH_CLIENT_CREDENTIALS,
        ]);

        $row = ExchangeOauthToken::query()->where('client_id', 'azure-client-123')->first();
        $this->assertNotNull($row);
        $raw = $row->getRawOriginal('access_token');
        $this->assertNotSame('access-secret-token', $raw);
        $this->assertSame('access-secret-token', Crypt::decryptString($raw));
        $this->assertFalse(is_file($legacy));

        $loaded = $store->get('azure-client-123');
        $this->assertSame('access-secret-token', $loaded['access_token']);
        $this->assertSame('refresh-secret-token', $loaded['refresh_token']);
    }

    public function test_legacy_plaintext_file_is_migrated_and_deleted(): void
    {
        $store = app(ExchangeOauthTokenStore::class);
        $legacy = $store->legacyFilePath();
        File::ensureDirectoryExists(dirname($legacy));
        File::put($legacy, json_encode([
            'legacy-client' => [
                'access_token' => 'file-access',
                'refresh_token' => 'file-refresh',
                'expires_at' => 1700000000,
                'auth_method' => 'client_credentials',
            ],
        ], JSON_THROW_ON_ERROR));

        $loaded = $store->get('legacy-client');

        $this->assertSame('file-access', $loaded['access_token']);
        $this->assertSame('file-refresh', $loaded['refresh_token']);
        $this->assertFalse(is_file($legacy));
        $this->assertDatabaseHas('exchange_oauth_tokens', [
            'client_id' => 'legacy-client',
        ]);
    }

    public function test_exchange_oauth_uses_database_store(): void
    {
        $oauth = new ExchangeOAuth(
            'tenant',
            'db-client',
            'secret',
            null,
            'https://graph.microsoft.com/.default',
            ExchangeOAuth::AUTH_CLIENT_CREDENTIALS,
            'from@example.com',
            'Mailer',
        );

        // Simulate a token fetch persisting via protected storeTokens through reflection-free public clear/put path.
        app(ExchangeOauthTokenStore::class)->put(
            'db-client',
            'mem-access',
            null,
            time() + 7200,
            ExchangeOAuth::AUTH_CLIENT_CREDENTIALS,
        );

        $oauth2 = new ExchangeOAuth(
            'tenant',
            'db-client',
            'secret',
            null,
            'https://graph.microsoft.com/.default',
            ExchangeOAuth::AUTH_CLIENT_CREDENTIALS,
            'from@example.com',
            'Mailer',
        );

        $this->assertTrue($oauth2->hasValidToken());
        $this->assertSame('mem-access', $oauth2->getAccessToken());
        unset($oauth);
    }
}
