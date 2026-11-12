<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['account_id', 'event', 'from_status', 'to_status', 'data', 'user_id'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
