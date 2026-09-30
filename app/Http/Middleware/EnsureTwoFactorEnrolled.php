<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige 2FA configurado quando obrigatório (Super Admin, ou clínica com
 * `security.require_2fa`) e troca de senha pendente.
 */
class EnsureTwoFactorEnrolled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $isAccountRoute = $request->routeIs('account.*', 'logout', 'api.auth.*');

        if ($user->must_change_password && ! $isAccountRoute) {
            return $this->deny($request, 'password_change_required', 'Troque sua senha para continuar.');
        }

        $required = $user->is_super_admin
            ? config('aivexa.security.super_admin_requires_2fa')
            : (bool) $user->company?->setting('security.require_2fa', false);

        if ($required && ! $user->hasTwoFactorEnabled() && ! $isAccountRoute) {
            return $this->deny($request, 'two_factor_enrollment_required', 'Configure a autenticação em dois fatores para continuar.');
        }

        return $next($request);
    }

    private function deny(Request $request, string $code, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'code' => $code], 403);
        }

        return redirect()->route('account.security')->with('warning', $message);
    }
}
