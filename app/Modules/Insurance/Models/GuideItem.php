<?php

namespace App\Modules\Insurance\Models;

use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Procedimento executado na guia (cópia do código/descrição/valor da tabela no momento). */
class GuideItem extends Model
{
    use BelongsToCompany, HasUlids;

    protected $table = 'insurance_guide_items';

    protected $fillable = ['guide_id', 'procedure_id', 'table_code', 'code', 'description', 'execution_date', 'quantity', 'unit_cents', 'total_cents', 'requires_authorization'];

    protected function casts(): array
    {
        return ['execution_date' => 'date', 'quantity' => 'integer', 'unit_cents' => 'integer', 'total_cents' => 'integer', 'requires_authorization' => 'boolean'];
    }

    protected static function booted(): void
    {
        $guard = function (GuideItem $item) {
            $guide = Guide::query()->whereKey($item->guide_id)->first(['status', 'batch_id']);
            if ($guide && ! $guide->isEditable()) {
                throw new LogicException('Itens de guia faturada não podem ser alterados.');
            }
        };
        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public function guide(): BelongsTo
    {
        return $this->belongsTo(Guide::class);
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }
}
