<?php

namespace App\Core\Tenancy;

use App\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Isolamento multi-cliente (defesa em profundidade):
 *  - leitura filtrada por account_id (e empresas autorizadas) — sem contexto, exceção;
 *  - na criação, account_id vem do contexto; valores divergentes são recusados;
 *  - account_id e company_id são imutáveis;
 *  - a empresa precisa estar entre as autorizadas para o usuário.
 */
trait BelongsToAccount
{
    public static function bootBelongsToAccount(): void
    {
        static::addGlobalScope(new AccountScope);

        static::creating(function (Model $model) {
            $ctx = app(TenantContext::class);
            if ($ctx->isSystem()) {
                if (empty($model->account_id)) {
                    throw new CrossTenantViolation('account_id obrigatório em modo sistema.');
                }

                return;
            }
            $accountId = $ctx->accountId();
            if (! empty($model->account_id) && $model->account_id !== $accountId) {
                throw new CrossTenantViolation;
            }
            $model->account_id = $accountId;

            $col = $model->companyColumn();
            if ($col !== null && $col !== 'id' && $model->{$col} !== null && ! $ctx->canAccessCompany((string) $model->{$col})) {
                throw new CrossTenantViolation('Empresa não autorizada para este usuário.');
            }
        });

        static::updating(function (Model $model) {
            $col = $model->companyColumn();
            if ($model->isDirty('account_id') || ($col !== null && $col !== 'id' && $model->isDirty($col))) {
                throw new CrossTenantViolation('Cliente/empresa de um registro não podem ser alterados.');
            }
        });
    }

    /** Coluna que identifica a empresa do registro (null = registro do cliente, sem empresa). */
    public function companyColumn(): ?string
    {
        return property_exists($this, 'companyColumn') ? $this->companyColumn : 'company_id';
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
