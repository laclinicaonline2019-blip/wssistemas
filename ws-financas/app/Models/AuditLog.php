<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Auditoria append-only: alteração e exclusão são proibidas pela aplicação. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['account_id', 'user_id', 'action', 'subject_type', 'subject_id', 'data', 'ip', 'channel', 'prev_hash', 'hash', 'created_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Auditoria não pode ser alterada.'));
        static::deleting(fn () => throw new LogicException('Auditoria não pode ser apagada pela aplicação.'));
    }
}
