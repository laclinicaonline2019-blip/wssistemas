<?php

namespace App\Modules\Platform\Http\Controllers\Web;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Services\AccessGuard;
use App\Modules\Platform\Http\Controllers\Api\CompanySettingsController;
use App\Modules\Platform\Http\Requests\CompanySettingsRequest;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Services\PlanLimitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CompanySettingsWebController extends Controller
{
    public function edit(TenantContext $context, PlanLimitService $limits): View
    {
        $company = Company::with('plan')->findOrFail($context->companyId());

        return view('company.edit', ['company' => $company, 'usage' => $limits->usage($company)]);
    }

    public function update(CompanySettingsRequest $request, TenantContext $context, AccessGuard $guard): RedirectResponse
    {
        CompanySettingsController::apply($request, $context, $guard);

        return back()->with('success', 'Configurações salvas.');
    }
}
