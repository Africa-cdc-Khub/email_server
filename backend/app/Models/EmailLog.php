<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'email_provider_id',
        'external_integration_id',
        'to',
        'subject',
        'status',
        'error_message',
        'driver',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function emailProvider(): BelongsTo
    {
        return $this->belongsTo(EmailProvider::class);
    }

    public function externalIntegration(): BelongsTo
    {
        return $this->belongsTo(ExternalIntegration::class);
    }

    public function sendingSystemLabel(): string
    {
        return $this->emailProvider?->name ?? ucfirst((string) ($this->driver ?: 'unknown'));
    }

    public function sourceLabel(): string
    {
        if ($this->relationLoaded('externalIntegration') && $this->externalIntegration) {
            return $this->externalIntegration->name;
        }

        if ($this->external_integration_id) {
            $this->loadMissing('externalIntegration');
            if ($this->externalIntegration) {
                return $this->externalIntegration->name;
            }
        }

        return match ($this->meta['source'] ?? null) {
            'password_reset' => 'Password reset',
            'two_factor_email' => 'Two-factor sign-in',
            'admin_test' => 'Provider test',
            'integration' => $this->externalIntegration?->name ?? 'Integration',
            'admin' => 'Admin panel',
            default => 'Admin panel',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toLogArray(): array
    {
        return [
            'id' => $this->id,
            'to' => $this->to,
            'subject' => $this->subject,
            'status' => $this->status,
            'driver' => $this->driver,
            'error_message' => $this->error_message,
            'sending_system' => $this->sendingSystemLabel(),
            'source' => $this->sourceLabel(),
            'email_provider' => $this->emailProvider ? [
                'id' => $this->emailProvider->id,
                'name' => $this->emailProvider->name,
            ] : null,
            'external_integration' => $this->externalIntegration ? [
                'id' => $this->externalIntegration->id,
                'name' => $this->externalIntegration->name,
            ] : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
