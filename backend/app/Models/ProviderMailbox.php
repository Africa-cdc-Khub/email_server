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
        'is_active',
        'daily_quota',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'daily_quota' => 'integer',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(EmailProvider::class, 'email_provider_id');
    }
}
