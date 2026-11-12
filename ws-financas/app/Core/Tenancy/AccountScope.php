<?php

namespace App\Core\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Filtro automático por cliente e, quando restrito (ex.: contador), pelas empresas autorizadas. */
class AccountScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $ctx = app(TenantContext::class);
        if ($ctx->isSystem()) {
            return;
        }
        if (! $ctx->hasAccount()) {
            throw new TenantContextMissing('Consulta em '.$model::class.' sem contexto de cliente.');
        }

        $builder->where($model->qualifyColumn('account_id'), $ctx->accountId());

        $companyColumn = $model->companyColumn();
        if ($companyColumn !== null && $ctx->companyIds() !== null) {
            $builder->whereIn($model->qualifyColumn($companyColumn), $ctx->companyIds() ?: ['-']);
        }
    }
}
