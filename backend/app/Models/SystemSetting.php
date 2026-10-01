<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemSetting extends Model
{
    public const MAIL_PENDING_RETRY_SECONDS = 'mail_pending_retry_seconds';

    protected $fillable = [
        'key',
        'value',
    ];

    public static function getValue(string $key, ?string $default = null): ?string
    {
        $cached = Cache::remember(
            self::cacheKey($key),
            60,
            function () use ($key): ?string {
                $row = static::query()->where('key', $key)->first();

                return $row?->value;
            },
        );

        if ($cached === null || $cached === '') {
            return $default;
        }

        return $cached;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $raw = self::getValue($key);

        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            return $default;
        }

        return (int) $raw;
    }

    public static function setValue(string $key, string|int|null $value): self
    {
        $row = static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value === null ? null : (string) $value],
        );

        Cache::forget(self::cacheKey($key));

        return $row;
    }

    public static function mailPendingRetrySeconds(): int
    {
        return max(0, self::getInt(self::MAIL_PENDING_RETRY_SECONDS, 60));
    }

    private static function cacheKey(string $key): string
    {
        return 'system_setting:'.$key;
    }
}
