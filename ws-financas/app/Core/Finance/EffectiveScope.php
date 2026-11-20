<?php

namespace App\Core\Finance;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Só lançamentos EFETIVOS: despesa pendente de aprovação (ou rejeitada) não entra em saldo, relatório, consulta,
 * lembrete ou meta. Telas de aprovação usam Entry::withPending().
 */
class EffectiveScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull($model->qualifyColumn('approval_status'));
    }
}
