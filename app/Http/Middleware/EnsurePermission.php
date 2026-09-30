<?php

namespace App\Http\Middleware;

use App\Core\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso: ->middleware('permission:filial.visualizar')
 *      ->middleware('permission:agenda.criar|agenda.editar')  (qualquer uma)
 */
class EnsurePermission
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();

        foreach (explode('|', $permissions) as $permission) {
            if ($user && $user->hasPermission($permission)) {
                return $next($request);
            }
        }

        $this->audit->record('access.denied', result: 'denied', metadata: [
            'permission' => $permissions,
            'route' => $request->route()?->getName(),
            'method' => $request->method(),
        ]);

        abort(403, 'Você não tem permissão para esta ação.');
    }
}
