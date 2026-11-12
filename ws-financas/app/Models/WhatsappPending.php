<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappPending extends Model
{
    protected $primaryKey = 'phone_hash';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['phone_hash', 'account_id', 'payload', 'expires_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'expires_at' => 'datetime'];
    }
}
