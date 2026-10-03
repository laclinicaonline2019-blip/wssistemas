<?php

namespace App\Modules\Reports\Models;

use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Finance\Models\Payable;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Demonstrativo mensal médico × clínica (retrato imutável com hash). */
class DoctorClosing extends Model
{
    use BelongsToCompany, HasUlids;

    public const STATUSES = ['closed' => 'Aguardando o médico', 'confirmed' => 'Confirmado pelo médico', 'disputed' => 'Contestado', 'superseded' => 'Substituído'];

    protected $fillable = ['doctor_id', 'period', 'version', 'status', 'data', 'hash', 'doctor_share_cents', 'to_pay_cents', 'payable_id', 'closed_by', 'closed_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'closed_at' => 'datetime', 'responded_at' => 'datetime'];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class)->withTrashed();
    }

    public function payable(): BelongsTo
    {
        return $this->belongsTo(Payable::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function periodLabel(): string
    {
        [$y, $m] = explode('-', $this->period);

        return ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'][(int) $m].'/'.$y;
    }

    /** O retrato confere com o hash gravado no fechamento? */
    public function intact(): bool
    {
        return hash_equals($this->hash, self::hashOf($this->data));
    }

    /** SHA-256 do JSON canônico (chaves ordenadas — o banco pode reordenar o JSON gravado). */
    public static function hashOf(array $data): string
    {
        $canon = function ($v) use (&$canon) {
            if (! is_array($v)) {
                return $v;
            }
            if (! array_is_list($v)) {
                ksort($v);
            }

            return array_map($canon, $v);
        };

        return hash('sha256', json_encode($canon($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
