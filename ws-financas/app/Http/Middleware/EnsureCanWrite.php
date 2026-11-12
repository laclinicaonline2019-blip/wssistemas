<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assinatura suspensa/cancelada: consulta continua liberada (dados preservados), novos lançamentos bloqueados.
 */
class EnsureCanWrite
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->attributes->get('account');
        if (! $request->isMethodSafe() && $account && ! $account->canWrite()) {
            $msg = 'Assinatura suspensa ou cancelada: seus dados continuam disponíveis para consulta, mas novos lançamentos estão bloqueados. Regularize em Assinatura.';
            if ($request->expectsJson()) {
                return response()->json(['message' => $msg, 'reason' => 'assinatura'], 402);
            }

            return back()->with('error', $msg);
        }

        return $next($request);
    }
}
