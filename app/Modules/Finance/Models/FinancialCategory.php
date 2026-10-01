<?php

namespace App\Modules\Finance\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class FinancialCategory extends Model
{
    use Auditable, BelongsToCompany, HasUlids;

    public const TYPES = ['income' => 'Receita', 'expense' => 'Despesa'];

    /** Plano de contas inicial de toda clínica. */
    public const DEFAULTS = [
        'income' => ['Consultas', 'Procedimentos', 'Exames', 'Convênios', 'Outras receitas'],
        'expense' => ['Aluguel e condomínio', 'Salários e encargos', 'Repasse médico', 'Materiais e insumos', 'Impostos e taxas',
            'Energia, água e internet', 'Sistemas e softwares', 'Marketing', 'Manutenção', 'Tarifas bancárias e de cartão', 'Outras despesas'],
    ];

    protected string $auditName = 'financial_category';

    protected $fillable = ['type', 'name', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
