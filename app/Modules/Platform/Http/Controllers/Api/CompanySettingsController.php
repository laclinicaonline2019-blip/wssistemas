<?php

namespace App\Modules\Platform\Http\Controllers\Api;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Services\AccessGuard;
use App\Modules\Platform\Http\Requests\CompanySettingsRequest;
use App\Modules\Platform\Http\Resources\CompanyResource;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Services\PlanLimitService;
use Illuminate\Http\JsonResponse;

/** Dados da própria clínica (tenant atual). */
class CompanySettingsController extends Controller
{
    public function show(TenantContext $context, PlanLimitService $limits): JsonResponse
    {
        $company = Company::with('plan')->findOrFail($context->companyId());

        return response()->json([
            'data' => new CompanyResource($company),
            'usage' => $limits->usage($company),
        ]);
    }

    public function update(CompanySettingsRequest $request, TenantContext $context, AccessGuard $guard): CompanyResource
    {
        $company = self::apply($request, $context, $guard);

        return new CompanyResource($company->load('plan'));
    }

    public static function apply(CompanySettingsRequest $request, TenantContext $context, AccessGuard $guard): Company
    {
        if (! $guard->hasCompanyWide($request->user(), 'empresa.editar')) {
            $guard->deny('company_wide_required', ['permission' => 'empresa.editar']);
        }

        $company = Company::findOrFail($context->companyId());
        $data = $request->validated();

        if (isset($data['settings'])) {
            $data['settings'] = array_replace_recursive($company->settings ?? [], $data['settings']);
        }

        $company->update($data);

        return $company;
    }
}
