<?php

namespace App\Modules\Insurance\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tabela de valores do convênio (geral ou de um plano), com vigência. */
class PriceTable extends Model
{
    use Auditable, BelongsToCompany, HasUlids;

    protected $table = 'insurance_price_tables';

    protected string $auditName = 'insurance_price_table';

    protected $fillable = ['insurer_id', 'plan_id', 'name', 'valid_from', 'valid_until', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_until' => 'date', 'is_active' => 'boolean'];
    }

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(InsurancePlan::class, 'plan_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PriceItem::class, 'price_table_id');
    }
}
