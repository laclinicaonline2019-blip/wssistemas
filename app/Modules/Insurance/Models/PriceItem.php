<?php

namespace App\Modules\Insurance\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Valor de um procedimento na tabela do convênio, exigência de autorização e coparticipação. */
class PriceItem extends Model
{
    use Auditable, BelongsToCompany, HasUlids;

    protected $table = 'insurance_price_items';

    protected string $auditName = 'insurance_price_item';

    protected $fillable = ['price_table_id', 'procedure_id', 'price_cents', 'requires_authorization', 'copay_type', 'copay_value'];

    protected $attributes = ['requires_authorization' => false, 'copay_type' => 'none', 'copay_value' => 0];

    protected function casts(): array
    {
        return ['price_cents' => 'integer', 'requires_authorization' => 'boolean', 'copay_value' => 'integer'];
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(PriceTable::class, 'price_table_id');
    }

    /** Coparticipação do paciente sobre o total do item (arredondamento comercial, nunca acima do total). */
    public function copayOf(int $total): int
    {
        return match ($this->copay_type) {
            'percent' => intdiv($total * $this->copay_value + 5000, 10000),
            'fixed' => min($this->copay_value, $total),
            default => 0,
        };
    }

    public function copayLabel(): string
    {
        return match ($this->copay_type) {
            'percent' => number_format($this->copay_value / 100, 2, ',', '.').'%',
            'fixed' => 'R$ '.number_format($this->copay_value / 100, 2, ',', '.'),
            default => '—',
        };
    }
}
