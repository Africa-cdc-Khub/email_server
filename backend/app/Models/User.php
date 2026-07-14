<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name',
    'email',
    'password',
    'is_admin',
    'is_active',
    'two_factor_email_enabled',
    'two_factor_totp_enabled',
    'two_factor_totp_secret',
    'two_factor_totp_recovery_codes',
])]
#[Hidden([
    'password',
    'remember_token',
    'two_factor_totp_secret',
    'two_factor_totp_recovery_codes',
])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
            'two_factor_email_enabled' => 'boolean',
            'two_factor_totp_enabled' => 'boolean',
            'two_factor_totp_secret' => 'encrypted',
            'two_factor_totp_recovery_codes' => 'array',
        ];
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_email_enabled || $this->two_factor_totp_enabled;
    }

    /**
     * @return list<string>
     */
    public function enabledTwoFactorMethods(): array
    {
        $methods = [];

        if ($this->two_factor_email_enabled) {
            $methods[] = 'email';
        }

        if ($this->two_factor_totp_enabled) {
            $methods[] = 'totp';
        }

        return $methods;
    }
}
