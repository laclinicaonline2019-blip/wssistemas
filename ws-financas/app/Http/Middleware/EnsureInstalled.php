<?php

namespace App\Http\Middleware;

use App\Services\Install\WebInstaller;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Middleware global: antes da instalação, só o instalador responde (nada que dependa de APP_KEY/banco roda). */
class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('testing') || $request->is('instalar', 'instalar/*', 'up') || app(WebInstaller::class)->locked()) {
            return $next($request);
        }
        if ($request->is('webhooks/*')) {
            return response('Not installed', 503);
        }

        return redirect('/instalar');
    }
}
