<?php

namespace App\Console\Commands;

use App\Services\ExchangeOauthTokenStore;
use Illuminate\Console\Command;

class MigrateExchangeOauthTokensCommand extends Command
{
    protected $signature = 'exchange:migrate-oauth-tokens';

    protected $description = 'Move plaintext exchange-oauth-tokens.json into encrypted DB storage and delete the file';

    public function handle(ExchangeOauthTokenStore $store): int
    {
        $path = $store->legacyFilePath();
        $existed = is_file($path);

        $store->migrateLegacyFileIfNeeded();
        $store->purgeLegacyFile();

        if ($existed && ! is_file($path)) {
            $this->info('Migrated Exchange OAuth tokens to encrypted database storage and deleted the plaintext JSON file.');
        } elseif (! $existed) {
            $this->info('No plaintext exchange-oauth-tokens.json found — nothing to migrate.');
        } else {
            $this->warn('Legacy file may still exist at: '.$path);
        }

        return self::SUCCESS;
    }
}
