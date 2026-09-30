<?php

namespace App\Modules\Platform\Http\Controllers\Web;

use App\Core\Health\HealthChecker;
use App\Http\Controllers\Controller;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Platform\Http\Controllers\Api\PlatformCompanyController;
use App\Modules\Platform\Http\Requests\CompanyStoreRequest;
use App\Modules\Platform\Http\Requests\PlanRequest;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\SaasPlan;
use App\Modules\Platform\Services\CompanyProvisioningService;
use App\Modules\Platform\Services\PlatformMetrics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlatformWebController extends Controller
{
    public function dashboard(PlatformMetrics $metrics, HealthChecker $health): View
    {
        $checks = $health->run();

        return view('platform.dashboard', [
            'metrics' => $metrics->summary(),
            'checks' => $checks,
            'healthy' => $health->healthy($checks),
            'recentCompanies' => Company::with('plan')->latest()->limit(6)->get(),
        ]);
    }

    public function companies(Request $request): View
    {
        $companies = Company::query()->with('plan')
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w->whereLike('trade_name', "%{$s}%")->orWhereLike('legal_name', "%{$s}%")))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('trade_name')->paginate(25)->withQueryString();

        return view('platform.companies.index', compact('companies'));
    }

    public function createCompany(): View
    {
        return view('platform.companies.create', ['plans' => SaasPlan::where('is_active', true)->orderBy('price_monthly_cents')->get()]);
    }

    public function storeCompany(CompanyStoreRequest $request, CompanyProvisioningService $service): RedirectResponse
    {
        $result = PlatformCompanyController::provision($request, $service);

        return redirect()->route('platform.companies.show', $result['company'])
            ->with('success', 'Clínica provisionada. O administrador deverá trocar a senha no primeiro acesso.');
    }

    public function showCompany(Company $company): View
    {
        return view('platform.companies.show', [
            'company' => $company->load('plan'),
            'plans' => SaasPlan::orderBy('price_monthly_cents')->get(),
            'stats' => [
                'users' => DB::table('users')->where('company_id', $company->id)->whereNull('deleted_at')->count(),
                'branches' => DB::table('branches')->where('company_id', $company->id)->whereNull('deleted_at')->count(),
            ],
            // Auditoria técnica: somente eventos administrativos, sem dados clínicos.
            'events' => AuditLog::query()->where('company_id', $company->id)
                ->where(fn ($q) => $q->where('action', 'like', 'company.%')->orWhere('action', 'like', 'auth.%'))
                ->orderByDesc('id')->limit(15)->get(),
        ]);
    }

    public function updateCompany(Request $request, Company $company): RedirectResponse
    {
        $request->validate([
            'status' => ['required', Rule::in(['trial', 'active', 'suspended', 'cancelled'])],
            'saas_plan_id' => ['nullable', 'exists:saas_plans,id'],
        ]);

        PlatformCompanyController::applyUpdate($request, $company);

        return back()->with('success', 'Empresa atualizada.');
    }

    public function plans(): View
    {
        return view('platform.plans', ['plans' => SaasPlan::withCount('companies')->orderBy('price_monthly_cents')->get()]);
    }

    public function storePlan(PlanRequest $request): RedirectResponse
    {
        SaasPlan::create($this->planData($request));

        return back()->with('success', 'Plano criado.');
    }

    public function updatePlan(PlanRequest $request, SaasPlan $plan): RedirectResponse
    {
        $plan->update($this->planData($request));

        return back()->with('success', 'Plano atualizado.');
    }

    private function planData(PlanRequest $request): array
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');
        $data['limits'] = array_merge($data['limits'] ?? [], [
            'ai_enabled' => $request->boolean('limits.ai_enabled'),
            'whatsapp_enabled' => $request->boolean('limits.whatsapp_enabled'),
        ]);

        return $data;
    }
}
