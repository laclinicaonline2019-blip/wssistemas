<?php

namespace App\Modules\Payments\Models;

use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Finance\Models\FinancialTransaction;
use App\Modules\Finance\Models\Receivable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Parte do médico em um recebimento (split nativo do gateway ou repasse interno). */
class PaymentSplit extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = ['doctor_id', 'receivable_id', 'transaction_id', 'charge_id', 'split_rule_id', 'base_cents', 'amount_cents', 'mode', 'status', 'payable_id', 'settled_at', 'source'];

    public const SOURCES = ['asaas' => 'ASAAS', 'cielo_api' => 'Cielo (online)', 'cielo_terminal' => 'Maquininha Cielo'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['base_cents' => 'integer', 'amount_cents' => 'integer', 'settled_at' => 'datetime'];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class);
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }
}
