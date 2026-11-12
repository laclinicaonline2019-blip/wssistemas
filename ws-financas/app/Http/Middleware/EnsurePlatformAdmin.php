<?php

namespace App\Http\Middleware;

use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdmin
{
    public function __construct(private readonly TenantContext $ctx) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_platform_admin, 404);

        return $this->ctx->runAsSystem(fn () => $next($request));
    }
}
