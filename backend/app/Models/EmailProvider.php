<?php

namespace App\Models;

use App\Enums\EmailDriver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailProvider extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'driver',
        'config',
        'from_address',
        'from_name',
        'is_default',
        'is_active',
        'priority',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'driver' => EmailDriver::class,
            'config' => 'encrypted:array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'priority' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (EmailProvider $provider): void {
            $from = trim((string) $provider->from_address);
            if ($from === '') {
                return;
            }

            if ($provider->mailboxes()->exists()) {
                return;
            }

            $provider->mailboxes()->create([
                'email' => strtolower($from),
                'is_active' => true,
                'daily_quota' => $provider->defaultMailboxQuota(),
            ]);
        });
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(ExternalIntegration::class);
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class);
    }

    public function mailboxes(): HasMany
    {
        return $this->hasMany(ProviderMailbox::class)->orderBy('id');
    }

    /**
     * Suggested per-mailbox daily send cap for this driver.
     * SMTP defaults to Hostinger's typical 500/day limit; Exchange/others use 10_000.
     */
    public function defaultMailboxQuota(): int
    {
        return match ($this->driver) {
            EmailDriver::Smtp => 500,
            default => 10000,
        };
    }

    /**
     * Keep legacy from_address in sync with the first active mailbox (compatibility).
     */
    public function syncLegacyFromAddress(?string $email): void
    {
        $this->forceFill(['from_address' => $email])->saveQuietly();
    }

    /**
     * @param  list<array{id?: int|null, email: string, is_active?: bool, daily_quota?: int}>  $rows
     */
    public function syncMailboxes(array $rows): void
    {
        $keepIds = [];

        foreach ($rows as $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email === '') {
                continue;
            }

            $payload = [
                'email' => $email,
                'is_active' => array_key_exists('is_active', $row) ? (bool) $row['is_active'] : true,
                'daily_quota' => max(1, (int) ($row['daily_quota'] ?? $this->defaultMailboxQuota())),
            ];

            $id = isset($row['id']) ? (int) $row['id'] : 0;
            if ($id > 0) {
                $mailbox = $this->mailboxes()->whereKey($id)->first();
                if ($mailbox) {
                    $mailbox->update($payload);
                    $keepIds[] = $mailbox->id;
                    continue;
                }
            }

            $mailbox = $this->mailboxes()->updateOrCreate(
                ['email' => $email],
                $payload
            );
            $keepIds[] = $mailbox->id;
        }

        if ($keepIds !== []) {
            $this->mailboxes()->whereNotIn('id', $keepIds)->delete();
        } else {
            $this->mailboxes()->delete();
        }

        $firstActive = $this->mailboxes()->where('is_active', true)->orderBy('id')->value('email');
        $this->syncLegacyFromAddress($firstActive ? (string) $firstActive : null);
    }

    public function configValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->safeConfig(), $key, $default);
    }

    /**
     * Read encrypted config without killing list/show when APP_KEY changed.
     *
     * @return array<string, mixed>
     */
    public function safeConfig(): array
    {
        try {
            $config = $this->config;

            return is_array($config) ? $config : [];
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    public function configIsReadable(): bool
    {
        try {
            $this->config;

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Assign a new config payload without decrypting existing ciphertext.
     *
     * Eloquent's encrypted cast decrypts the original attribute when syncing
     * dirty state — that throws "The MAC is invalid" after an APP_KEY change.
     * Clearing the raw attribute first lets us re-encrypt fresh values.
     *
     * @param  array<string, mixed>  $config
     */
    public function setConfigSafely(array $config): void
    {
        if (! $this->configIsReadable()) {
            $attributes = $this->getAttributes();
            $attributes['config'] = null;
            $this->setRawAttributes($attributes, true);
        }

        $this->config = $config;
    }
}
