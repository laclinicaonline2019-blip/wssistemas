<?php

namespace App\Http\Middleware;

use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\TenantContext;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Branch;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Define o contexto de tenant a partir do USUÁRIO AUTENTICADO (backend).
 * A filial atual pode ser escolhida (header X-Branch-Id na API / sessão no
 * web), mas é sempre validada contra os vínculos do usuário.
 */
class ResolveTenant
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        if ($user->is_super_admin && ! $request->expectsJson() && $request->isMethod('GET')) {
            return redirect()->route('platform.dashboard');
        }

        if ($user->is_super_admin || $user->company_id === null) {
            abort(403, 'Área exclusiva das clínicas. Utilize o painel da plataforma.');
        }

        if (! $user->isActive()) {
            $this->logoutUser($request);
            abort(403, 'Usuário bloqueado.');
        }

        $company = $user->company;

        if (! $company || ! $company->isOperational()) {
            abort(403, 'O acesso da sua clínica está suspenso. Contate o suporte.');
        }

        $allowed = $user->allowedBranchIds();
        $this->context->set($company->id, null, $allowed);

        $requested = $request->headers->get('X-Branch-Id')
            ?? ($request->hasSession() ? $request->session()->get('current_branch_id') : null);

        $branchId = $this->resolveBranch($requested, $allowed);

        if ($requested !== null && $branchId !== $requested) {
            $this->audit->record('access.branch_denied', result: 'denied', metadata: ['branch_id' => $requested]);

            if ($request->headers->has('X-Branch-Id')) {
                abort(403, 'Filial não permitida para este usuário.');
            }

            $request->session()->forget('current_branch_id');
        }

        $this->context->setBranch($branchId);
        $request->attributes->set('company', $company);

        try {
            return $next($request);
        } finally {
            // Nada de contexto "vazando" para a próxima requisição (workers persistentes/testes).
            $this->context->clear();
        }
    }

    private function resolveBranch(?string $requested, ?array $allowed): ?string
    {
        if ($requested !== null) {
            $valid = Branch::query()->active()->accessible($allowed)->whereKey($requested)->exists();

            if ($valid) {
                return $requested;
            }
        }

        // Usuário restrito a filiais: usa a primeira filial ativa permitida.
        if ($allowed !== null) {
            return Branch::query()->active()->accessible($allowed)->orderByDesc('is_headquarters')->orderBy('name')->value('id');
        }

        return null; // visão consolidada (todas as filiais)
    }

    private function logoutUser(Request $request): void
    {
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
        }
    }
}
