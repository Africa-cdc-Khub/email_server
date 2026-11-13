<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderMailbox extends Model
{
    use HasFactory;

    protected $fillable = [
        'email_provider_id',
        'email',
        'smtp_username',
        'smtp_password',
        'is_active',
        'daily_quota',
        'hourly_quota',
        'weight',
    ];

    protected $hidden = [
        'smtp_password',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'daily_quota' => 'integer',
            'hourly_quota' => 'integer',
            'weight' => 'integer',
            'smtp_password' => 'encrypted',
        ];
    }

    public function hasSmtpPassword(): bool
    {
        $raw = $this->attributes['smtp_password'] ?? null;

        return is_string($raw) && $raw !== '';
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(EmailProvider::class, 'email_provider_id');
    }
}
