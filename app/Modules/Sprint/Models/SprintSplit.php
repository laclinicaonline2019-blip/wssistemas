<?php

namespace App\Modules\Sprint\Models;

use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lançamento da divisão de um pagamento: parte do profissional, da clínica (casa), comissão da
 * plataforma ou tarifa do gateway. Estorno = linha negativa ou status "reversed".
 */
class SprintSplit extends Model
{
    use BelongsToCompany, HasUlids;

    public const PARTIES = ['professional' => 'Profissional', 'house' => 'Clínica (casa)', 'platform' => 'Comissão Melhor Sprint', 'fee' => 'Tarifa do gateway'];

    public const STATUSES = ['pending' => 'A repassar', 'settled' => 'Liquidado', 'reversed' => 'Estornado', 'to_invoice' => 'A cobrar', 'invoiced' => 'Cobrado'];

    protected $fillable = ['sale_id', 'payment_id', 'transaction_id', 'party', 'professional_id', 'amount_cents', 'mode', 'status', 'source', 'payable_id', 'settled_at'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'settled_at' => 'datetime'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(SprintSale::class, 'sale_id');
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(SprintProfessional::class, 'professional_id');
    }
}
