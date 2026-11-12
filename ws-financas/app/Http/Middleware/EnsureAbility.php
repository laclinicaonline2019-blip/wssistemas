<?php

namespace App\Http\Middleware;

use App\Core\Access\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        abort_unless(Permissions::can($ability), 403, 'Seu perfil não tem permissão para esta ação.');

        return $next($request);
    }
}
