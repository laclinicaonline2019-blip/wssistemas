<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Services\InboundService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Webhook do WhatsApp (rota pública, sem sessão/CSRF; GET = verificação, POST = eventos assinados). */
class WhatsAppWebhookController extends Controller
{
    public function __invoke(Request $request, string $channel, InboundService $inbound): Response
    {
        abort_unless(preg_match('/^[0-9A-Za-z]{26}$/', $channel) === 1, 404);
        [$status, $body] = $inbound->handle($channel, $request);

        return response((string) $body, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
