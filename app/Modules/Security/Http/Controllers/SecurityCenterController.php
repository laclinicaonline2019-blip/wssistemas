<?php

namespace App\Modules\Security\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Company;
use App\Modules\Security\Services\RetentionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Central de segurança da clínica (auditoria.visualizar) e política de retenção (empresa.editar). */
class SecurityCenterController extends Controller
{
    private const EVENTS = ['auth.login.failed', 'auth.account.locked', 'security.file_blocked', 'report.exported', 'patient.exported', 'patient_file.downloaded',
        'ai.media_downloaded', 'billing.suspended', 'retention.applied', 'user.2fa_disabled', 'messaging.unofficial_risk_accepted'];

    public function index(TenantContext $context, RetentionService $retention): View
    {
        $cid = $context->companyId();
        $since = now()->subDays(30);
        $count = fn (string|array $actions) => DB::table('audit_logs')->where('company_id', $cid)->whereIn('action', (array) $actions)->where('created_at', '>=', $since)->count();
        $users = User::query()->where('company_id', $cid)->where('status', 'active')->orderBy('name')->get();
        $privileged = $users->filter(fn (User $u) => $u->hasPermission('perfil.gerenciar') || $u->hasPermission('usuario.criar') || $u->hasPermission('assinatura.gerenciar'));

        return view('security.index', [
            'kpi' => ['Logins com falha' => $count('auth.login.failed'), 'Contas bloqueadas' => $count('auth.account.locked'),
                'Arquivos barrados' => $count('security.file_blocked'), 'Exportações de dados' => $count(['report.exported', 'patient.exported'])],
            'no2fa' => $privileged->filter(fn ($u) => ! $u->two_factor_confirmed_at)->values(),
            'locked' => $users->filter(fn ($u) => $u->locked_until && $u->locked_until->isFuture())->values(),
            'tokens' => DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $users->pluck('id'))
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(),
            'events' => DB::table('audit_logs')->where('company_id', $cid)->whereIn('action', self::EVENTS)->orderByDesc('created_at')->limit(40)
                ->get(['action', 'result', 'user_id', 'ip_address', 'metadata', 'created_at']),
            'names' => $users->pluck('name', 'id'),
            'policy' => $retention->policy(Company::query()->findOrFail($cid)), 'labels' => RetentionService::LABELS,
            'scanner' => config('aivexa.security.scanner.driver'),
        ]);
    }

    public function saveRetention(Request $request, TenantContext $context): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('empresa.editar'), 403);
        $rules = collect(RetentionService::DEFAULTS)->mapWithKeys(fn ($v, $k) => [$k => ['required', 'integer', $k === 'whatsapp_text' ? 'between:0,3650' : 'between:30,3650']])->all();
        $data = $request->validate($rules, ['*.between' => 'Prazo entre :min e :max dias.']);
        $company = Company::query()->findOrFail($context->companyId());
        $settings = $company->settings ?? [];
        $settings['retention'] = array_map('intval', $data);
        $company->update(['settings' => $settings]);

        return back()->with('success', 'Política de retenção salva. A rotina roda diariamente às 04:10.');
    }
}
