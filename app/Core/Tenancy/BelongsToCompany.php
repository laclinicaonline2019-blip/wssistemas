<?php

namespace App\Core\Tenancy;

use App\Core\Tenancy\Exceptions\CrossTenantViolation;
use App\Modules\Platform\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Isolamento lógico multi-tenant para models de dados de clínica.
 *
 * - Toda consulta é filtrada por company_id do TenantContext (falha fechada).
 * - Na criação, company_id é preenchido pelo contexto; valores divergentes
 *   enviados por qualquer camada são rejeitados.
 * - company_id nunca pode ser alterado depois de criado.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function (Model $model) {
            $context = app(TenantContext::class);

            if ($context->isSystem()) {
                if (empty($model->company_id)) {
                    throw new CrossTenantViolation('company_id obrigatório ao criar registro em modo sistema.');
                }

                return;
            }

            $companyId = $context->companyId();

            if (! empty($model->company_id) && $model->company_id !== $companyId) {
                throw new CrossTenantViolation;
            }

            $model->company_id = $companyId;
        });

        static::updating(function (Model $model) {
            if ($model->isDirty('company_id')) {
                throw new CrossTenantViolation('company_id é imutável.');
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
