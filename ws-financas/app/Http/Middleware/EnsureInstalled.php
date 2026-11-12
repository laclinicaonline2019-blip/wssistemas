<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! file_exists(storage_path('app/installed')) && ! app()->environment('testing') && ! $request->is('instalar*')) {
            return redirect('/instalar');
        }

        return $next($request);
    }
}
