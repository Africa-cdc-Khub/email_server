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

    public function integrations(): HasMany
    {
        return $this->hasMany(ExternalIntegration::class);
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class);
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
}
