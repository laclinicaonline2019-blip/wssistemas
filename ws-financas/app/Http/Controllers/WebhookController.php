<?php

namespace App\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Models\WebhookEvent;
use App\Services\Billing\BillingService;
use App\Services\WhatsApp\InboundProcessor;
use App\Services\WhatsApp\WebhookDiagnostics;
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
     * Mensagens recebidas (Cloud API). Cada etapa fica no diagnóstico (storage/logs/whatsapp-AAAA-MM-DD.log), sem conteúdo privado.
     * A Meta recebe 200 assim que a assinatura confere; o processamento roda em seguida, na mesma requisição
     * (com PHP-FPM/LiteSpeed a conexão já foi liberada; em CGI a resposta só fecha ao final — os dois funcionam na HostGator).
     */
    public function whatsapp(Request $request, WhatsAppClient $wa, InboundProcessor $inbound, TenantContext $ctx): Response
    {
        $rid = WebhookDiagnostics::begin();
        WebhookDiagnostics::info('recebido', ['ip' => $request->ip(), 'bytes' => strlen($request->getContent()),
            'assinatura_presente' => $request->headers->has('X-Hub-Signature-256'), 'tipo' => (string) $request->header('Content-Type')]);

        $sig = $wa->signatureStatus($request);
        if (! in_array($sig, ['ok', 'dispensada_dev'], true)) {
            WebhookDiagnostics::warning('assinatura_recusada', ['motivo' => $sig, 'tamanho_segredo' => strlen((string) config('services.whatsapp.app_secret'))]);
            Log::warning('Webhook WhatsApp com assinatura inválida', ['ip' => $request->ip(), 'motivo' => $sig, 'req' => $rid]);

            return response('Invalid signature', 401);
        }
        WebhookDiagnostics::info('assinatura_ok', ['resultado' => $sig]);

        $payload = $request->json()->all();
        $messages = $wa->parse($payload);
        $statuses = $wa->parseStatuses($payload);
        WebhookDiagnostics::info('conteudo', ['objeto' => $payload['object'] ?? null, 'mensagens' => count($messages), 'status' => count($statuses),
            'campos' => array_values(array_unique(array_map(fn ($c) => (string) ($c['field'] ?? '?'), array_merge(...array_map(fn ($e) => (array) ($e['changes'] ?? []), (array) ($payload['entry'] ?? [])))))) ?: null]);
        if (! $messages && ! $statuses) {
            WebhookDiagnostics::warning('sem_mensagens', ['observacao' => 'corpo sem entry/changes/value.messages ou statuses — nada a processar']);
        }

        // continua mesmo se a Meta encerrar a conexão após o 200
        ignore_user_abort(true);
        app()->terminating(function () use ($messages, $statuses, $inbound, $ctx, $rid) {
            @set_time_limit(120);
            foreach ($messages as $m) {
                try {
                    $ctx->clear();
                    $inbound->process($m);
                } catch (\Throwable $e) {
                    WebhookDiagnostics::error('erro_processamento', WebhookDiagnostics::exception($e) + ['mensagem' => WebhookDiagnostics::ref($m['wamid'] ?? null)]);
                    report($e);
                }
            }
            foreach ($statuses as $st) {
                try {
                    $inbound->status($st);
                } catch (\Throwable $e) {
                    WebhookDiagnostics::error('erro_status', WebhookDiagnostics::exception($e));
                    report($e);
                }
            }
            WebhookDiagnostics::info('concluido', ['req' => $rid]);
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
