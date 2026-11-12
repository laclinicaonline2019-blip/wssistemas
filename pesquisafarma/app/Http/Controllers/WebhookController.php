<?php

namespace App\Http\Controllers;

use App\Services\WhatsApp\WhatsAppGateway;
use App\Services\WhatsAppBot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebhookController extends Controller
{
    private function authorizeSecret(string $secret): void
    {
        $expected = (string) config('pesquisafarma.webhook_secret');
        abort_unless($expected !== '' && hash_equals($expected, $secret), 404);
    }

    /** Verificação do webhook da Meta. */
    public function verify(Request $request, string $secret)
    {
        $this->authorizeSecret($secret);
        abort_unless($request->query('hub_verify_token') === config('pesquisafarma.meta.verify_token'), 403);

        return response((string) $request->query('hub_challenge'));
    }

    public function receive(Request $request, string $secret, WhatsAppGateway $gateway, WhatsAppBot $bot)
    {
        $this->authorizeSecret($secret);
        // WhatsApp oficial: confere a assinatura HMAC que a Meta envia em cada chamada
        if (config('pesquisafarma.whatsapp_driver') === 'meta') {
            $appSecret = (string) config('pesquisafarma.meta.app_secret');
            $sig = (string) $request->header('X-Hub-Signature-256');
            abort_unless($appSecret !== '' && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $appSecret), $sig), 403);
        }
        foreach ($gateway->parse($request) as $message) {
            try {
                $bot->handle($message);
            } catch (Throwable $e) {
                Log::error('Falha ao processar mensagem do WhatsApp: '.$e->getMessage(), ['exception' => $e]);
            }
        }

        return response()->json(['ok' => true]);
    }
}
