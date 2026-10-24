<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuthFlow extends Model
{
    protected $guarded = [];

    protected $hidden = ['state_hash', 'session_hash', 'verifier', 'nonce_hash'];

    protected function casts(): array
    {
        return ['verifier' => 'encrypted', 'expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
