<?php

namespace App\Http\Controllers\Web;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Audit\Http\Controllers\Api\AuditLogController;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Branch;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Services\PlanLimitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Dashboard da clínica. Nesta fase exibe apenas indicadores REAIS já
 * disponíveis (estrutura, acessos, segurança). Indicadores de agenda,
 * atendimento e financeiro entram com os respectivos módulos.
 */
class DashboardController extends Controller
{
    public function index(Request $request, TenantContext $context, PlanLimitService $limits): View
    {
        $user = $request->user();
        $company = Company::with('plan')->findOrFail($context->companyId());
        $allowed = $context->allowedBranchIds();

        $recent = $user->hasPermission('auditoria.visualizar')
            ? AuditLogController::query($context)->with('user:id,name')->orderByDesc('id')->limit(8)->get()
            : collect();

        $failedLogins = $user->hasPermission('auditoria.visualizar')
            ? AuditLogController::query($context)->whereIn('action', ['auth.login.failed', 'auth.account.locked'])->where('created_at', '>=', now()->subDay())->count()
            : null;

        return view('dashboard', [
            'company' => $company,
            'usage' => $limits->usage($company),
            'branches' => Branch::query()->accessible($allowed)->orderByDesc('is_headquarters')->orderBy('name')->get(),
            'usersCount' => User::query()->manageableBy($allowed)->count(),
            'usersWithout2fa' => User::query()->manageableBy($allowed)->whereNull('two_factor_confirmed_at')->count(),
            'recent' => $recent,
            'patients' => $user->hasPermission('paciente.visualizar') ? [
                'active' => Patient::query()->where('status', 'active')->count(),
                'new_month' => Patient::query()->where('created_at', '>=', now()->startOfMonth())->count(),
            ] : null,
            'doctorsActive' => $user->hasPermission('medico.visualizar') ? Doctor::query()->active()->inBranches($allowed)->count() : null,
            'failedLogins' => $failedLogins,
        ]);
    }

    /** Troca a filial de trabalho (validada novamente pelo ResolveTenant). */
    public function switchBranch(Request $request, TenantContext $context): RedirectResponse
    {
        $data = $request->validate(['branch_id' => ['nullable', 'string', 'size:26']]);
        $branchId = $data['branch_id'] ?? null;

        if ($branchId !== null) {
            $ok = Branch::query()->active()->accessible($context->allowedBranchIds())->whereKey($branchId)->exists();
            abort_unless($ok, 403);
            $request->session()->put('current_branch_id', $branchId);
        } else {
            abort_if($context->allowedBranchIds() !== null, 403);
            $request->session()->forget('current_branch_id');
        }

        return back();
    }
}
