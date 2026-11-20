<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pedido de mudança de plano (ex.: plano personalizado acima de 50 empresas) — atendido pelo proprietário. */
class PlanChangeRequest extends Model
{
    use BelongsToAccount, HasUlids;

    protected ?string $companyColumn = null;

    public const STATUSES = ['pendente' => 'Pendente', 'atendido' => 'Atendido', 'recusado' => 'Recusado', 'cancelado' => 'Cancelado'];

    protected $fillable = ['from_plan_id', 'to_plan_id', 'companies_wanted', 'message', 'status', 'requested_by', 'decided_by', 'decided_at'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'companies_wanted' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function fromPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'from_plan_id');
    }

    public function toPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'to_plan_id');
    }
}
