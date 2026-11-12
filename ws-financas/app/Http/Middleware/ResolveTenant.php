<?php

namespace App\Http\Middleware;

use App\Core\Tenancy\TenantContext;
use App\Models\Company;
use App\Models\Membership;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Monta o contexto do cliente a partir do usuário logado + vínculo ativo (sessão guarda só QUAL vínculo;
 * a validade é conferida a cada requisição no banco). Um contador com vários clientes troca pelo menu.
 */
class ResolveTenant
{
    public function __construct(private readonly TenantContext $ctx) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $memberships = Membership::query()->withoutGlobalScopes()->with('account.subscription.plan')
            ->where('user_id', $user->id)->where('status', 'active')->get()
            ->filter(fn ($m) => $m->account !== null); // cancelado continua com acesso de leitura até a exclusão (LGPD)

        $m = $memberships->firstWhere('id', $request->session()->get('membership_id')) ?? $memberships->first();
        if (! $m) {
            if ($user->is_platform_admin) {
                return redirect()->route('admin.dashboard');
            }
            abort(403, 'Seu usuário não está vinculado a nenhum cliente ativo.');
        }
        $request->session()->put('membership_id', $m->id);

        $allowed = $this->ctx->runFor($m->account_id, fn () => $m->allowedCompanyIds());
        $this->ctx->set($m->account_id, $allowed, $m->role, $user->id);

        $companies = Company::query()->where('active', true)->orderBy('name')->get();
        $request->attributes->set('membership', $m);
        $request->attributes->set('account', $m->account);
        $request->attributes->set('companies', $companies);

        View::share(['membership' => $m, 'account' => $m->account, 'companies' => $companies, 'memberships' => $memberships]);

        return $next($request);
    }
}
