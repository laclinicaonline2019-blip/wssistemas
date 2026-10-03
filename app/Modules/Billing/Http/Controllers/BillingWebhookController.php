<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Webhook do gateway da plataforma (rota pública; autenticada por token e confirmada na API). */
class BillingWebhookController extends Controller
{
    public function __invoke(Request $request, SubscriptionService $service): Response
    {
        [$status, $body] = $service->handleWebhook($request);

        return response($body, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
