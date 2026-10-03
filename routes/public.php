<?php

use App\Modules\Billing\Http\Controllers\BillingWebhookController;
use App\Modules\Documents\Http\Controllers\Web\DocumentValidationController;
use App\Modules\Messaging\Http\Controllers\WhatsAppWebhookController;
use App\Modules\Payments\Http\Controllers\Web\PublicPaymentController;
use App\Modules\Payments\Http\Controllers\Web\WebhookController;
use App\Modules\Queue\Http\Controllers\Web\PanelController;
use Illuminate\Support\Facades\Route;

/*
| Rotas públicas sem sessão/cookies: painel de chamadas da TV (acesso por token
| secreto da unidade). Não gravam sessão a cada atualização do painel.
*/
Route::get('painel/{token}', [PanelController::class, 'show'])->name('panel.show');
Route::get('painel/{token}/estado', [PanelController::class, 'state'])->name('panel.state');

// Validação pública de documentos médicos (QR Code impresso no rodapé).
Route::middleware('throttle:30,1')->group(function () {
    Route::get('validar', [DocumentValidationController::class, 'form'])->name('documents.validate.form');
    Route::get('validar/consulta', [DocumentValidationController::class, 'lookup'])->name('documents.validate.lookup');
    Route::get('validar/{code}', [DocumentValidationController::class, 'show'])->name('documents.validate');
});

// Fase 8 — webhooks dos gateways (autenticados por token) e página pública da cobrança.
Route::post('webhooks/pagamentos/{gateway}', WebhookController::class)->middleware('throttle:240,1')->name('payments.webhook');
Route::get('pagar/{token}', PublicPaymentController::class)->middleware('throttle:60,1')->name('payments.public');
// Cartão tokenizado no navegador (Cielo Silent Order Post): o servidor recebe só o PaymentToken.
Route::get('pagar/{token}/cartao/sessao', [PublicPaymentController::class, 'cardSession'])->middleware('throttle:20,1')->name('payments.card_session');
Route::post('pagar/{token}/cartao', [PublicPaymentController::class, 'payCard'])->middleware('throttle:10,1')->name('payments.card_pay');

// Fase 11 — webhook do WhatsApp (GET verificação / POST eventos assinados).
// Fase 16 — gateway da plataforma (assinaturas das clínicas).
Route::post('webhooks/assinaturas/asaas', BillingWebhookController::class)->middleware('throttle:240,1')->name('billing.webhook');
Route::match(['get', 'post'], 'webhooks/whatsapp/{channel}', WhatsAppWebhookController::class)->middleware('throttle:600,1')->name('messaging.webhook');
