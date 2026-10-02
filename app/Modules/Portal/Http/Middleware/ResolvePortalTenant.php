<?php

namespace App\Modules\Portal\Http\Middleware;

use App\Core\Tenancy\TenantContext;
use App\Modules\Platform\Models\Company;
use App\Modules\Portal\Models\PatientAccount;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal do paciente: a clínica vem do endereço (/portal/{clinic}); o paciente logado
 * precisa ser dessa clínica, estar ativo e não bloqueado. Define o contexto de tenant
 * (somente leitura do próprio paciente — as consultas filtram por patient_id).
 */
class ResolvePortalTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = (string) $request->route('clinic');
        $company = Company::query()->where('slug', $slug)->first();

        if (! $company || ! $company->isOperational() || ! $company->setting('portal.enabled', true)) {
            abort(404);
        }

        // Os controllers não recebem {clinic}; route() o preenche sozinho.
        $request->route()->forgetParameter('clinic');
        URL::defaults(['clinic' => $company->slug]);
        $this->context->set($company->id);
        $request->attributes->set('portal_company', $company);

        try {
            $guard = Auth::guard('patient');
            // Relido a cada requisição: bloqueio pela clínica derruba a sessão na hora.
            $account = $guard->id() ? PatientAccount::query()->find($guard->id()) : null;

            if ($account) {
                if ($account->company_id !== $company->id || ! $account->isActive() || $account->isLocked()) {
                    $guard->logout();
                    $request->session()->regenerate();
                } else {
                    $request->attributes->set('portal_account_id', $account->id);
                }
            }

            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
