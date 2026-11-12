<?php

namespace App\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Models\WebhookEvent;
use App\Services\Billing\BillingService;
use App\Services\WhatsApp\InboundProcessor;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    /** Verificação do webhook pela Meta (configuração no painel de desenvolvedores). */
    public function whatsappVerify(Request $request): Response
    {
        $token = (string) config('services.whatsapp.verify_token');
        if ($request->query('hub_mode') === 'subscribe' && $token !== '' && hash_equals($token, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
        }

        return response('Forbidden', 403);
    }

    /**
     * Mensagens recebidas. Respondemos 200 rápido; o processamento (regras, sem processo permanente) cabe no tempo da requisição.
     * Se a hospedagem tiver PHP-FPM, a resposta à Meta sai antes do processamento (fastcgi_finish_request).
     */
    public function whatsapp(Request $request, WhatsAppClient $wa, InboundProcessor $inbound, TenantContext $ctx): Response
    {
        if (! $wa->verifySignature($request)) {
            Log::warning('Webhook WhatsApp com assinatura inválida', ['ip' => $request->ip()]);

            return response('Invalid signature', 401);
        }
        $messages = $wa->parse($request->json()->all());
        app()->terminating(function () use ($messages, $inbound, $ctx) {
            foreach ($messages as $m) {
                try {
                    $ctx->clear();
                    $inbound->process($m);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });

        return response('OK', 200);
    }

    /** Asaas: token no cabeçalho + idempotência pelo id do evento + reconsulta do pagamento na API. */
    public function asaas(Request $request, BillingService $billing, TenantContext $ctx): Response
    {
        $gw = $billing->gateway();
        if (! $gw->verifyWebhook($request)) {
            return response('Unauthorized', 401);
        }
        $eventId = (string) ($request->input('id') ?: hash('sha256', $request->getContent()));
        try {
            $evt = WebhookEvent::query()->create(['source' => 'asaas', 'event_id' => $eventId, 'event' => (string) $request->input('event')]);
        } catch (QueryException) {
            return response()->json(['ok' => true, 'duplicado' => true]);
        }

        $paymentId = (string) $request->input('payment.id', '');
        if ($paymentId !== '' && str_starts_with((string) $request->input('event'), 'PAYMENT_')) {
            try {
                $ctx->runAsSystem(function () use ($gw, $billing, $paymentId) {
                    $p = $gw->fetchPayment($paymentId); // nunca confiar só no corpo do webhook
                    if ($p) {
                        $billing->applyPayment($p);
                    }
                });
            } catch (\Throwable $e) {
                $evt->delete(); // o Asaas reenvia; a sincronização diária também cobre
                report($e);

                return response('Retry', 500);
            }
        }
        $evt->update(['processed_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
