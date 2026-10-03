<?php

namespace App\Services;

use App\Models\ExchangeOauthToken;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Persist Exchange OAuth access/refresh tokens in the DB (APP_KEY encrypted).
 * Replaces the plaintext storage/app/exchange-oauth-tokens.json file.
 */
class ExchangeOauthTokenStore
{
    public const LEGACY_FILENAME = 'exchange-oauth-tokens.json';

    /**
     * @return array{
     *     access_token: ?string,
     *     refresh_token: ?string,
     *     expires_at: ?int,
     *     auth_method: ?string
     * }|null
     */
    public function get(string $clientId): ?array
    {
        $clientId = trim($clientId);
        if ($clientId === '') {
            return null;
        }

        $this->migrateLegacyFileIfNeeded();

        $row = ExchangeOauthToken::query()->where('client_id', $clientId)->first();
        if ($row === null) {
            return null;
        }

        return [
            'access_token' => $row->access_token,
            'refresh_token' => $row->refresh_token,
            'expires_at' => $row->expires_at !== null ? (int) $row->expires_at : null,
            'auth_method' => $row->auth_method,
        ];
    }

    public function put(
        string $clientId,
        ?string $accessToken,
        ?string $refreshToken,
        ?int $expiresAt,
        ?string $authMethod,
    ): void {
        $clientId = trim($clientId);
        if ($clientId === '') {
            return;
        }

        ExchangeOauthToken::query()->updateOrCreate(
            ['client_id' => $clientId],
            [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at' => $expiresAt,
                'auth_method' => $authMethod,
            ],
        );

        $this->purgeLegacyFile();
    }

    public function forget(string $clientId): void
    {
        $clientId = trim($clientId);
        if ($clientId === '') {
            return;
        }

        ExchangeOauthToken::query()->where('client_id', $clientId)->delete();
        $this->purgeLegacyFile();
    }

    public function legacyFilePath(): string
    {
        return storage_path('app/'.self::LEGACY_FILENAME);
    }

    /**
     * Import plaintext JSON tokens into encrypted DB rows, then delete the file.
     */
    public function migrateLegacyFileIfNeeded(): void
    {
        $path = $this->legacyFilePath();
        if (! is_file($path)) {
            return;
        }

        try {
            $raw = File::get($path);
            $tokenData = json_decode($raw ?: '', true);
            if (! is_array($tokenData)) {
                $this->purgeLegacyFile();

                return;
            }

            foreach ($tokenData as $clientId => $tokens) {
                if (! is_string($clientId) || $clientId === '' || ! is_array($tokens)) {
                    continue;
                }

                // Do not overwrite newer DB rows with stale file data.
                if (ExchangeOauthToken::query()->where('client_id', $clientId)->exists()) {
                    continue;
                }

                ExchangeOauthToken::query()->create([
                    'client_id' => $clientId,
                    'access_token' => $tokens['access_token'] ?? null,
                    'refresh_token' => $tokens['refresh_token'] ?? null,
                    'expires_at' => isset($tokens['expires_at']) ? (int) $tokens['expires_at'] : null,
                    'auth_method' => $tokens['auth_method'] ?? null,
                ]);
            }

            $this->purgeLegacyFile();
            Log::info('Migrated Exchange OAuth tokens from plaintext JSON into encrypted database storage.');
        } catch (Throwable $e) {
            Log::warning('Failed to migrate legacy Exchange OAuth token file: '.$e->getMessage());
        }
    }

    public function purgeLegacyFile(): void
    {
        $path = $this->legacyFilePath();
        if (! is_file($path)) {
            return;
        }

        try {
            File::delete($path);
        } catch (Throwable $e) {
            Log::warning('Could not delete legacy Exchange OAuth token file: '.$e->getMessage());
        }
    }
}
