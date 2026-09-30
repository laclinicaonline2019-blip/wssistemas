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
use App\Modules\Queue\Models\QueueTicket;
use App\Modules\Scheduling\Models\Appointment;
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
            'today' => $user->hasPermission('agenda.visualizar') ? $this->today($allowed, $context->branchId()) : null,
            'doctorsActive' => $user->hasPermission('medico.visualizar') ? Doctor::query()->active()->inBranches($allowed)->count() : null,
            'failedLogins' => $failedLogins,
        ]);
    }

    /** Indicadores do dia (agenda e fila) da filial de trabalho ou de todas as acessíveis. */
    private function today(?array $allowed, ?string $branchId): array
    {
        $tz = 'America/Sao_Paulo';
        $range = [now($tz)->startOfDay()->utc(), now($tz)->endOfDay()->utc()];
        $base = fn () => Appointment::query()->accessible($allowed)->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->whereBetween('starts_at', $range);
        $counts = $base()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'scheduled' => (int) $counts->only(['scheduled', 'confirmed', 'arrived', 'in_service', 'completed'])->sum(),
            'completed' => (int) ($counts['completed'] ?? 0),
            'cancelled' => (int) ($counts['cancelled'] ?? 0),
            'no_show' => (int) ($counts['no_show'] ?? 0),
            'waiting' => QueueTicket::query()->when($branchId, fn ($q) => $q->where('branch_id', $branchId), fn ($q) => $allowed === null ? $q : $q->whereIn('branch_id', $allowed))
                ->where('service_date', now($tz)->toDateString())->where('status', 'waiting')->count(),
            'next' => $base()->whereIn('status', ['scheduled', 'confirmed', 'arrived'])->where('starts_at', '>=', now()->subMinutes(30))
                ->with(['patient:id,name,social_name', 'doctor:id,name,social_name'])->orderBy('starts_at')->limit(8)->get(),
        ];
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
