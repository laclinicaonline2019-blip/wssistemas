<?php

namespace App\Modules\Payments\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Recebe notificações dos gateways (rota pública, sem sessão/CSRF; autenticada por token). */
class WebhookController extends Controller
{
    public function __invoke(Request $request, string $gateway, PaymentService $payments): JsonResponse
    {
        abort_unless(preg_match('/^[0-9A-Za-z]{26}$/', $gateway) === 1, 404);
        [$status, $message] = $payments->handleWebhook($gateway, $request);

        return response()->json(['message' => $message], $status);
    }
}
