<?php

namespace App\Core\Tenancy;

use App\Core\Tenancy\Exceptions\TenantContextMissing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isSystem()) {
            return;
        }

        if (! $context->hasCompany()) {
            throw new TenantContextMissing(sprintf(
                'Consulta em %s sem contexto de tenant. Use TenantContext::runFor() ou runAsSystem().',
                $model::class,
            ));
        }

        $builder->where($model->qualifyColumn('company_id'), $context->companyId());
    }
}
