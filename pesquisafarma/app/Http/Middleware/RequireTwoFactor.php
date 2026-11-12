<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;

/** Administradores (e, se configurado, toda a equipe) só usam o sistema com a verificação em duas etapas ativa. */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next)
    {
        $u = $request->user();
        $required = $u && ! $u->two_factor_confirmed_at && (
            ($u->isAdmin() && Setting::get('require_2fa_admin') === '1') || ($u->isStaff() && Setting::get('require_2fa_staff') === '1')
        );
        if ($required && ! $request->routeIs('2fa.*', 'logout')) {
            return redirect()->route('2fa.setup')->with('error', 'Por segurança, ative a verificação em duas etapas para continuar.');
        }

        return $next($request);
    }
}
