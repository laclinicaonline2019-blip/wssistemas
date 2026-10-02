<?php

namespace App\Modules\Portal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Área logada do portal (depois de ResolvePortalTenant). */
class PortalAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->attributes->get('portal_account_id') || ! Auth::guard('patient')->check()) {
            return redirect()->guest(route('portal.login'));
        }

        return $next($request);
    }
}
