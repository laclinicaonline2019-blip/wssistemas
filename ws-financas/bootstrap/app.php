<?php

use App\Core\Support\BusinessRule;
use App\Core\Tenancy\CrossTenantViolation;
use App\Core\Tenancy\TenantContextMissing;
use App\Http\Middleware\EnsureAbility;
use App\Http\Middleware\EnsureCanWrite;
use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureTwoFactor;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->web(prepend: [EnsureInstalled::class]);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'ability' => EnsureAbility::class,
            'can.write' => EnsureCanWrite::class,
            'platform' => EnsurePlatformAdmin::class,
            '2fa' => EnsureTwoFactor::class,
        ]);

        // O contexto do cliente precisa existir ANTES do route model binding: {entry} de outro cliente vira 404.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsurePlatformAdmin::class);

        // Webhooks são autenticados por assinatura/token próprios, não por CSRF.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('app'));

        $proxies = (string) env('TRUSTED_PROXIES', '');
        if ($proxies !== '') {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $r) => $r->is('app/api/*') || $r->expectsJson());

        // Falhas de isolamento nunca vazam detalhes: 404 genérico + log.
        $exceptions->render(function (TenantContextMissing|CrossTenantViolation $e, Request $request) {
            report($e);

            return $request->expectsJson() || $request->is('app/api/*') ? response()->json(['message' => 'Registro não encontrado.'], 404) : abort(404);
        });

        $exceptions->render(function (BusinessRule $e, Request $request) {
            if ($request->expectsJson() || $request->is('app/api/*')) {
                return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
            }

            return back()->withInput($request->except(['password', 'password_confirmation']))->with('error', $e->getMessage());
        });

        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'code']);
    })->create();
