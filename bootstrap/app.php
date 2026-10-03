<?php

use App\Core\Tenancy\Exceptions\CrossTenantViolation;
use App\Core\Tenancy\Exceptions\TenantContextMissing;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Modules\Portal\Http\Middleware\ResolvePortalTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Instalador web: fora do grupo "web" (sem sessão/cookies — as tabelas ainda não existem).
        then: function () {
            Route::group([], base_path('routes/install.php'));
            Route::group([], base_path('routes/public.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'platform' => EnsureSuperAdmin::class,
            'permission' => EnsurePermission::class,
            '2fa.enrolled' => EnsureTwoFactorEnrolled::class,
        ]);

        // O contexto de tenant precisa existir ANTES do route model binding,
        // para que {branch}/{user}/{role} sejam resolvidos já filtrados pela empresa.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureSuperAdmin::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolvePortalTenant::class);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('home'));

        // Proxies reversos confiáveis (nginx/load balancer) — ajuste TRUSTED_PROXIES.
        // Fase 17: "cloudflare" = faixas oficiais da Cloudflare (config/proxies.php); ou lista de IPs/CIDRs.
        $proxies = (string) env('TRUSTED_PROXIES', '');
        $middleware->trustProxies(at: match (true) {
            $proxies === '' => null,
            strtolower($proxies) === 'cloudflare' => (require __DIR__.'/../config/proxies.php')['cloudflare'],
            default => array_map('trim', explode(',', $proxies)),
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Falhas de isolamento nunca devem vazar detalhes: 403 genérico + log.
        $exceptions->render(function (TenantContextMissing|CrossTenantViolation $e, Request $request) {
            report($e);

            return $request->is('api/*') || $request->expectsJson()
                ? response()->json(['message' => 'Acesso não permitido.'], 403)
                : abort(403);
        });

        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'admin_password', 'code', 'recovery_code']);
    })->create();
