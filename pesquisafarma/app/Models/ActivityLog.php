<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Registro de tudo que cada televendas/farmácia/admin faz no sistema. */
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['meta' => 'array', 'created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
