<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Administradores precisam ter 2FA ativo antes de usar o sistema. */
class EnsureTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->hasTwoFactor() && $user->requiresTwoFactor() && ! $request->routeIs('security.*', 'logout')) {
            return redirect()->route('security.2fa')->with('error', 'Por segurança, administradores precisam ativar a verificação em duas etapas.');
        }

        return $next($request);
    }
}
