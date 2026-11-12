<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\Pharmacy\PanelController;
use App\Http\Controllers\PharmacyResponseController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\Staff;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect(auth()->user()->isPharmacy() ? '/farmacia' : '/painel') : redirect('/login'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'form'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
});
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// WhatsApp (Z-API / Evolution / Meta / mock)
Route::get('/webhook/whatsapp/{secret}', [WebhookController::class, 'verify']);
Route::post('/webhook/whatsapp/{secret}', [WebhookController::class, 'receive'])->name('webhook.whatsapp');

// Resposta rápida da farmácia pelo link do WhatsApp (sem login)
Route::get('/f/{token}', [PharmacyResponseController::class, 'form'])->name('pharmacy.respond');
Route::post('/f/{token}', [PharmacyResponseController::class, 'save'])->middleware('throttle:30,1');
Route::post('/f/{token}/recusar', [PharmacyResponseController::class, 'decline'])->name('pharmacy.decline');

// Área do cliente
Route::prefix('cliente')->name('portal.')->group(function () {
    Route::get('/entrar', [PortalController::class, 'loginForm'])->name('login');
    Route::post('/entrar', [PortalController::class, 'sendCode'])->middleware('throttle:5,1')->name('code');
    Route::post('/verificar', [PortalController::class, 'verify'])->middleware('throttle:10,1')->name('verify');
    Route::middleware('customer')->group(function () {
        Route::get('/', [PortalController::class, 'home'])->name('home');
        Route::post('/nota', [PortalController::class, 'uploadReceipt'])->name('receipt');
        Route::post('/brindes/{reward}', [PortalController::class, 'redeem'])->name('redeem');
        Route::post('/avaliar/{quote}', [PortalController::class, 'feedback'])->name('feedback');
        Route::post('/sair', [PortalController::class, 'logout'])->name('logout');
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/arquivo/{type}/{id}', [FileController::class, 'show'])->name('file');

    // ---------------- Painel Pesquisa Farma (admin + televendas)
    Route::prefix('painel')->middleware('can:staff')->group(function () {
        Route::get('/', [Staff\DashboardController::class, 'index'])->name('dashboard');

        Route::get('/conversas', [Staff\InboxController::class, 'index'])->name('inbox');
        Route::get('/conversas/{customer}', [Staff\InboxController::class, 'show'])->name('inbox.show');
        Route::post('/conversas/{customer}', [Staff\InboxController::class, 'send'])->name('inbox.send');
        Route::post('/conversas/{customer}/cartao', [Staff\InboxController::class, 'sendCard'])->name('inbox.card');
        Route::post('/mensagens/{message}/cotacao', [Staff\InboxController::class, 'toQuote'])->name('inbox.to_quote');
        Route::post('/mensagens/{message}/nota', [Staff\InboxController::class, 'toReceipt'])->name('inbox.to_receipt');

        Route::get('/cotacoes', [Staff\QuoteController::class, 'index'])->name('quotes.index');
        Route::get('/cotacoes/nova', [Staff\QuoteController::class, 'create'])->name('quotes.create');
        Route::post('/cotacoes', [Staff\QuoteController::class, 'store'])->name('quotes.store');
        Route::get('/cotacoes/{quote}', [Staff\QuoteController::class, 'show'])->name('quotes.show');
        Route::get('/cotacoes/{quote}/status', [Staff\QuoteController::class, 'status'])->name('quotes.status');
        Route::put('/cotacoes/{quote}', [Staff\QuoteController::class, 'update'])->name('quotes.update');
        Route::post('/cotacoes/{quote}/itens', [Staff\QuoteController::class, 'saveItems'])->name('quotes.items');
        Route::post('/cotacoes/{quote}/ler-ia', [Staff\QuoteController::class, 'readAi'])->name('quotes.ai');
        Route::post('/cotacoes/{quote}/enviar', [Staff\QuoteController::class, 'dispatch'])->name('quotes.dispatch');
        Route::post('/cotacoes/{quote}/resposta/{dispatch}', [Staff\QuoteController::class, 'respondFor'])->name('quotes.respond_for');
        Route::post('/cotacoes/{quote}/apresentar', [Staff\QuoteController::class, 'present'])->name('quotes.present');
        Route::post('/cotacoes/{quote}/pedido', [Staff\QuoteController::class, 'order'])->name('quotes.order');
        Route::post('/cotacoes/{quote}/perdida', [Staff\QuoteController::class, 'lost'])->name('quotes.lost');
        Route::post('/cotacoes/{quote}/avaliacao', [Staff\QuoteController::class, 'feedback'])->name('quotes.feedback');

        Route::get('/pedidos', [Staff\OrderController::class, 'index'])->name('orders.index');
        Route::get('/pedidos/{order}', [Staff\OrderController::class, 'show'])->name('orders.show');
        Route::post('/pedidos/{order}/entregue', [Staff\OrderController::class, 'complete'])->name('orders.complete');
        Route::post('/pedidos/{order}/resolvido', [Staff\OrderController::class, 'resolveIssue'])->name('orders.resolve');
        Route::post('/pedidos/{order}/perguntar-entrega', [Staff\OrderController::class, 'askDelivery'])->name('orders.ask');
        Route::post('/pedidos/{order}/cancelar', [Staff\OrderController::class, 'cancel'])->name('orders.cancel');

        Route::resource('clientes', Staff\CustomerController::class)->parameters(['clientes' => 'customer'])->names('customers')->except('destroy');
        Route::post('/clientes/{customer}/pontos', [Staff\CustomerController::class, 'adjustPoints'])->name('customers.points');

        Route::get('/notas', [Staff\ReceiptController::class, 'index'])->name('receipts.index');
        Route::post('/notas', [Staff\ReceiptController::class, 'store'])->name('receipts.store');
        Route::get('/notas/{receipt}', [Staff\ReceiptController::class, 'show'])->name('receipts.show');
        Route::post('/notas/{receipt}/conferir', [Staff\ReceiptController::class, 'verify'])->name('receipts.verify');
        Route::post('/notas/{receipt}/rejeitar', [Staff\ReceiptController::class, 'reject'])->name('receipts.reject');
        Route::post('/notas/{receipt}/ler-ia', [Staff\ReceiptController::class, 'readAi'])->name('receipts.ai');

        Route::get('/promocoes', [Staff\PromotionController::class, 'index'])->name('promotions.index');
        Route::post('/promocoes', [Staff\PromotionController::class, 'store'])->name('promotions.store');
        Route::post('/promocoes/{promotion}/status', [Staff\PromotionController::class, 'status'])->name('promotions.status');
        Route::post('/promocoes/{promotion}/divulgar', [Staff\PromotionController::class, 'broadcast'])->name('promotions.broadcast');

        Route::get('/relatorios/{report?}', [Staff\ReportController::class, 'show'])->name('reports');

        Route::get('/comissoes', [Staff\CommissionController::class, 'index'])->name('commissions.index');
        Route::post('/comissoes', [Staff\CommissionController::class, 'generate'])->name('commissions.generate');
        Route::get('/comissoes/{statement}', [Staff\CommissionController::class, 'show'])->name('commissions.show');
        Route::post('/comissoes/{statement}/status', [Staff\CommissionController::class, 'status'])->name('commissions.status');

        Route::get('/fidelidade', [Staff\LoyaltyController::class, 'index'])->name('loyalty');
        Route::post('/fidelidade/regras', [Staff\LoyaltyController::class, 'saveRule'])->name('loyalty.rule');
        Route::post('/fidelidade/regras/{rule}/alternar', [Staff\LoyaltyController::class, 'toggleRule'])->name('loyalty.rule.toggle');
        Route::post('/fidelidade/brindes', [Staff\LoyaltyController::class, 'saveReward'])->name('loyalty.reward');
        Route::post('/fidelidade/brindes/{reward}/alternar', [Staff\LoyaltyController::class, 'toggleReward'])->name('loyalty.reward.toggle');
        Route::post('/fidelidade/clientes/{customer}/trocar', [Staff\LoyaltyController::class, 'redeemFor'])->name('loyalty.redeem');
        Route::post('/fidelidade/trocas/{redemption}', [Staff\LoyaltyController::class, 'redemption'])->name('loyalty.redemption');
        Route::post('/fidelidade/premiacao', [Staff\LoyaltyController::class, 'saveCampaign'])->name('loyalty.campaign');
        Route::post('/fidelidade/premiacao/{campaign}/sortear', [Staff\LoyaltyController::class, 'draw'])->name('loyalty.draw');

        Route::get('/atividades', [Staff\ActivityController::class, 'index'])->name('activity');

        Route::middleware('can:admin')->group(function () {
            Route::resource('farmacias', Staff\PharmacyController::class)->parameters(['farmacias' => 'pharmacy'])->names('pharmacies')->except('destroy');
            Route::post('/farmacias/{pharmacy}/zonas', [Staff\PharmacyController::class, 'saveZone'])->name('pharmacies.zone');
            Route::delete('/farmacias/{pharmacy}/zonas/{zone}', [Staff\PharmacyController::class, 'deleteZone'])->name('pharmacies.zone.delete');
            Route::post('/farmacias/{pharmacy}/usuarios', [Staff\PharmacyController::class, 'addUser'])->name('pharmacies.user');

            Route::get('/bairros', [Staff\NeighborhoodController::class, 'index'])->name('neighborhoods.index');
            Route::post('/bairros', [Staff\NeighborhoodController::class, 'store'])->name('neighborhoods.store');
            Route::put('/bairros/{neighborhood}', [Staff\NeighborhoodController::class, 'update'])->name('neighborhoods.update');
            Route::post('/bairros/importar', [Staff\NeighborhoodController::class, 'import'])->name('neighborhoods.import');

            Route::get('/usuarios', [Staff\UserController::class, 'index'])->name('users.index');
            Route::post('/usuarios', [Staff\UserController::class, 'store'])->name('users.store');
            Route::put('/usuarios/{user}', [Staff\UserController::class, 'update'])->name('users.update');

            Route::get('/configuracoes', [Staff\SettingsController::class, 'edit'])->name('settings');
            Route::post('/configuracoes', [Staff\SettingsController::class, 'update'])->name('settings.update');
        });
    });

    // ---------------- Painel da farmácia
    Route::prefix('farmacia')->name('panel.')->middleware('can:pharmacy')->group(function () {
        Route::get('/', [PanelController::class, 'index'])->name('home');
        Route::get('/cotacoes/{dispatch}', [PanelController::class, 'quote'])->name('quote');
        Route::post('/cotacoes/{dispatch}', [PanelController::class, 'respond'])->name('respond');
        Route::post('/cotacoes/{dispatch}/recusar', [PanelController::class, 'decline'])->name('decline');
        Route::get('/pedidos', [PanelController::class, 'orders'])->name('orders');
        Route::post('/pedidos/{order}/entregue', [PanelController::class, 'completeOrder'])->name('orders.complete');
        Route::get('/vendas', [PanelController::class, 'sales'])->name('sales');
        Route::get('/promocoes', [PanelController::class, 'promotions'])->name('promotions');
        Route::post('/promocoes', [PanelController::class, 'storePromotion'])->name('promotions.store');
    });
});
