<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoogleAuthToken extends Model
{
    protected $fillable = [
        'token',
        'expires_at',
        'refresh_token',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
