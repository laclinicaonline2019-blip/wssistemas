<?php

namespace App\Core\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Escopo de usuários.
 *
 * Exceção documentada à regra de "falha fechada": a autenticação (sessão e
 * tokens Sanctum) precisa localizar o usuário ANTES de existir contexto de
 * tenant. Por isso, sem contexto não há filtro; com contexto de empresa, só
 * usuários daquela empresa são visíveis. Todas as rotas de clínica passam pelo
 * middleware ResolveTenant antes de chegar aos controllers (coberto por testes).
 */
class TenantUserScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->hasCompany()) {
            $builder->where($model->qualifyColumn('company_id'), $context->companyId());
        }
    }
}
