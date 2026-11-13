<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ExternalIntegration extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'api_key_hash',
        'api_key_prefix',
        'email_provider_id',
        'provider_mailbox_id',
        'allowed_ips',
        'settings',
        'is_active',
        'last_used_at',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'allowed_ips' => 'array',
            'settings' => 'array',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function emailProvider(): BelongsTo
    {
        return $this->belongsTo(EmailProvider::class);
    }

    /**
     * Provider id used for sending. Inactive sticky pins are ignored so the
     * fleet default (e.g. after switching Exchange → SMTP) is used instead.
     */
    public function resolvedEmailProviderId(): ?int
    {
        if ($this->email_provider_id === null) {
            return null;
        }

        $active = EmailProvider::query()
            ->whereKey($this->email_provider_id)
            ->where('is_active', true)
            ->exists();

        return $active ? (int) $this->email_provider_id : null;
    }

    public function providerMailbox(): BelongsTo
    {
        return $this->belongsTo(ProviderMailbox::class, 'provider_mailbox_id');
    }

    /**
     * When true, all mail from this client uses the priority queue (login/OTP systems).
     */
    public function prioritizesTokens(): bool
    {
        $settings = $this->settings ?? [];

        return (bool) ($settings['priority_tokens'] ?? false);
    }

    public function setPriorityTokens(bool $enabled): void
    {
        $settings = $this->settings ?? [];
        $settings['priority_tokens'] = $enabled;
        $this->settings = $settings;
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public static function hashClientSecret(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function clientSecretHint(string $plain): string
    {
        return substr($plain, 0, 4).'••••';
    }

    public static function generateClientSecret(int $length = 32): string
    {
        return Str::password($length, letters: true, numbers: true, symbols: true);
    }

    public function verifyClientSecret(string $plain): bool
    {
        return hash_equals($this->api_key_hash, self::hashClientSecret($plain));
    }

    /** @deprecated Use verifyClientSecret() */
    public function verifyApiKey(string $plain): bool
    {
        return $this->verifyClientSecret($plain);
    }

    /** @deprecated Use hashClientSecret() */
    public static function hashApiKey(string $plain): string
    {
        return self::hashClientSecret($plain);
    }

    /** @deprecated Use clientSecretHint() */
    public static function apiKeyPrefix(string $plain): string
    {
        return self::clientSecretHint($plain);
    }

    public function allowsIp(?string $ip): bool
    {
        $allowed = $this->allowed_ips ?? [];

        if ($allowed === []) {
            return true;
        }

        return $ip !== null && in_array($ip, $allowed, true);
    }
}
