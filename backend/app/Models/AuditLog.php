<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'actor_type',
        'external_integration_id',
        'user_name',
        'user_email',
        'action',
        'event_type',
        'http_method',
        'request_uri',
        'target_table',
        'target_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'is_suspicious',
        'suspicious_reasons',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'is_suspicious' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function externalIntegration(): BelongsTo
    {
        return $this->belongsTo(ExternalIntegration::class);
    }
}
