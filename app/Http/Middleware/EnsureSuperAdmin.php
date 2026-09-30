<?php

namespace App\Http\Middleware;

use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Área da plataforma SaaS (Super Admin). Executa em modo sistema (sem tenant). */
class EnsureSuperAdmin
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_super_admin || ! $user->isActive()) {
            $this->audit->record('access.platform_denied', result: 'denied', metadata: ['route' => $request->path()]);
            abort(403, 'Área restrita à administração da plataforma.');
        }

        return $this->context->runAsSystem(fn () => $next($request));
    }
}
