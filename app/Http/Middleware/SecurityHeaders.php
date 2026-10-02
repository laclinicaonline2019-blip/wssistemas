<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public const SAME_ORIGIN_FRAME_ROUTES = ['queue.print', 'print.test', 'documents.print', 'documents.print_group', 'documents.pdf', 'patient_files.download', 'transactions.receipt', 'cash.print'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        // Páginas de impressão podem ser embutidas pelo próprio sistema (iframe oculto
        // que dispara a impressão da senha); todas as demais nunca podem ser embutidas.
        $printable = $request->routeIs(...self::SAME_ORIGIN_FRAME_ROUTES);

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', $printable ? 'SAMEORIGIN' : 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(self), geolocation=(), payment=()');
        // Página pública de pagamento com cartão: o script de tokenização da Cielo (Silent Order Post)
        // envia o cartão do navegador direto para a Cielo — liberado só nesta rota.
        $sop = $request->routeIs('payments.public') ? ' https://transaction.pagador.com.br https://transactionsandbox.pagador.com.br' : '';

        $headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self'".$sop,
            "style-src 'self' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data: blob:",
            "connect-src 'self'".$sop,
            "frame-src 'self'".$sop,
            $printable ? "frame-ancestors 'self'" : "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]));

        if (config('aivexa.security.hsts') && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Respostas autenticadas nunca devem ficar em cache compartilhado.
        if ($request->user()) {
            $headers->set('Cache-Control', 'no-store, private');
        }

        $headers->remove('X-Powered-By');

        return $response;
    }
}
