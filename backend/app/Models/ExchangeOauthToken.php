<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeOauthToken extends Model
{
    protected $fillable = [
        'client_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'auth_method',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'integer',
        ];
    }
}
