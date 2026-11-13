<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Auditoria append-only: alteração e exclusão são proibidas pela aplicação. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['account_id', 'user_id', 'action', 'subject_type', 'subject_id', 'data', 'ip', 'channel', 'prev_hash', 'hash', 'created_at', 'hash_version', 'created_at_utc'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime', 'hash_version' => 'integer'];
    }

    /** Horário de exibição (Brasília): v2 usa o instante UTC assinado; v1 mantém o horário histórico gravado. */
    public function displayTime(): string
    {
        if ($this->created_at_utc) {
            return CarbonImmutable::createFromFormat('Y-m-d\\TH:i:s.u\\Z', $this->created_at_utc, 'UTC')->setTimezone(config('app.timezone'))->format('d/m/Y H:i:s');
        }

        return (string) $this->created_at?->format('d/m/Y H:i');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Auditoria não pode ser alterada.'));
        static::deleting(fn () => throw new LogicException('Auditoria não pode ser apagada pela aplicação.'));
    }
}
