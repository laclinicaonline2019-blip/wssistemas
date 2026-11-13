<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SignupController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WhatsappController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------- público
Route::get('/', [PageController::class, 'home'])->name('home');
Route::get('/privacidade', [PageController::class, 'privacy'])->name('privacy');
Route::get('/termos', [PageController::class, 'terms'])->name('terms');

Route::middleware('guest')->group(function () {
    Route::get('/entrar', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/entrar', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::get('/entrar/verificacao', [AuthController::class, 'showTwoFactor'])->name('login.2fa');
    Route::post('/entrar/verificacao', [AuthController::class, 'twoFactor'])->middleware('throttle:login');
    Route::get('/cadastro', [SignupController::class, 'show'])->name('signup');
    Route::post('/cadastro', [SignupController::class, 'store'])->middleware('throttle:signup');
    Route::get('/esqueci-senha', [AuthController::class, 'showForgot'])->name('password.request');
    Route::post('/esqueci-senha', [AuthController::class, 'forgot'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/redefinir-senha/{token}', [AuthController::class, 'showReset'])->name('password.reset');
    Route::post('/redefinir-senha', [AuthController::class, 'reset'])->middleware('throttle:10,1')->name('password.update');
});

// ---------------------------------------------------------------- webhooks (assinatura/token próprios)
Route::middleware('throttle:webhooks')->group(function () {
    Route::get('/webhooks/whatsapp', [WebhookController::class, 'whatsappVerify']);
    Route::post('/webhooks/whatsapp', [WebhookController::class, 'whatsapp']);
    Route::post('/webhooks/asaas', [WebhookController::class, 'asaas']);
});

// ---------------------------------------------------------------- autenticado
Route::middleware(['auth', '2fa'])->group(function () {
    Route::post('/sair', [AuthController::class, 'logout'])->name('logout');

    Route::get('/seguranca', [SecurityController::class, 'show'])->name('security.show');
    Route::get('/seguranca/2fa', [SecurityController::class, 'twoFactor'])->name('security.2fa');
    Route::post('/seguranca/2fa', [SecurityController::class, 'enableTwoFactor'])->name('security.2fa.enable');
    Route::delete('/seguranca/2fa', [SecurityController::class, 'disableTwoFactor'])->name('security.2fa.disable');
    Route::post('/seguranca/senha', [SecurityController::class, 'password'])->name('security.password');

    // ------------------------------------------------------------ painel do cliente
    Route::prefix('app')->middleware(['tenant', 'can.write'])->group(function () {
        Route::get('/', [PageController::class, 'app'])->middleware('ability:financeiro.ver')->name('app');
        Route::post('/trocar-cliente', [PageController::class, 'switchAccount'])->name('app.switch');

        Route::prefix('api')->middleware('throttle:api')->group(function () {
            Route::get('/mes', [ApiController::class, 'month'])->middleware('ability:financeiro.ver');
            Route::get('/fluxo', [ApiController::class, 'cashflow'])->middleware('ability:financeiro.ver');
            Route::get('/relatorio', [ApiController::class, 'report'])->middleware('ability:financeiro.ver');
            Route::post('/ia/analise', [ApiController::class, 'analysis'])->middleware(['ability:financeiro.ver', 'throttle:assistant']);
            Route::middleware('ability:financeiro.lancar')->group(function () {
                Route::post('/lancamentos', [ApiController::class, 'store']);
                Route::put('/lancamentos/{entry}', [ApiController::class, 'update']);
                Route::post('/lancamentos/{entry}/pago', [ApiController::class, 'togglePaid']);
                Route::delete('/lancamentos/{entry}', [ApiController::class, 'destroy']);
                Route::post('/dividas', [ApiController::class, 'storeDebt']);
                Route::put('/dividas/{debt}', [ApiController::class, 'updateDebt']);
                Route::delete('/dividas/{debt}', [ApiController::class, 'destroyDebt']);
                Route::put('/config', [ApiController::class, 'settings']);
                Route::post('/mensagem', [ApiController::class, 'message'])->middleware('throttle:assistant');
                Route::post('/exemplo', [ApiController::class, 'sample']);
                Route::delete('/exemplo', [ApiController::class, 'clearSample']);
            });
        });

        Route::get('/relatorios/exportar', [ReportController::class, 'export'])->middleware('ability:relatorios.exportar')->name('reports.export');

        Route::middleware('ability:empresas.gerenciar')->group(function () {
            Route::get('/empresas', [CompanyController::class, 'index'])->name('companies.index');
            Route::post('/empresas', [CompanyController::class, 'store'])->name('companies.store');
            Route::put('/empresas/{company}', [CompanyController::class, 'update'])->name('companies.update');
            Route::post('/empresas/{company}/contas', [CompanyController::class, 'storeBank'])->name('banks.store');
            Route::put('/contas-bancarias/{bankAccount}', [CompanyController::class, 'updateBank'])->name('banks.update');
        });

        Route::middleware('ability:usuarios.gerenciar')->group(function () {
            Route::get('/usuarios', [UserController::class, 'index'])->name('users.index');
            Route::post('/usuarios', [UserController::class, 'store'])->name('users.store');
            Route::put('/usuarios/{membership}', [UserController::class, 'update'])->name('users.update');
        });

        Route::middleware('ability:whatsapp.gerenciar')->group(function () {
            Route::get('/whatsapp', [WhatsappController::class, 'index'])->name('whatsapp.index');
            Route::post('/whatsapp', [WhatsappController::class, 'store'])->name('whatsapp.store');
            Route::put('/whatsapp/{contact}', [WhatsappController::class, 'update'])->name('whatsapp.update');
            Route::delete('/whatsapp/{contact}', [WhatsappController::class, 'destroy'])->name('whatsapp.destroy');
        });

        Route::middleware('ability:dados.importar')->group(function () {
            Route::get('/importar', [ImportController::class, 'show'])->name('import.show');
            Route::post('/importar', [ImportController::class, 'store'])->name('import.store');
        });

        Route::middleware('ability:assinatura.gerenciar')->withoutMiddleware('can.write')->group(function () {
            Route::get('/assinatura', [BillingController::class, 'show'])->name('billing.show');
            Route::post('/assinatura', [BillingController::class, 'subscribe'])->name('billing.subscribe');
            Route::post('/assinatura/cancelar', [BillingController::class, 'cancel'])->name('billing.cancel');
            Route::post('/assinatura/faturas/{invoice}/simular-pagamento', [BillingController::class, 'simulatePayment'])->name('billing.simulate');
        });

        Route::middleware('ability:lgpd.gerenciar')->withoutMiddleware('can.write')->group(function () {
            Route::get('/privacidade', [PrivacyController::class, 'show'])->name('lgpd.show');
            Route::get('/privacidade/exportar', [PrivacyController::class, 'export'])->name('lgpd.export');
        });

        Route::get('/auditoria', [PrivacyController::class, 'audit'])->middleware('ability:auditoria.ver')->name('audit.index');
    });

    // ------------------------------------------------------------ painel do proprietário (plataforma)
    Route::prefix('admin')->name('admin.')->middleware('platform')->group(function () {
        Route::get('/', [Admin\DashboardController::class, 'index'])->name('dashboard');
        Route::get('/clientes', [Admin\AccountController::class, 'index'])->name('accounts.index');
        Route::post('/clientes', [Admin\AccountController::class, 'store'])->name('accounts.store');
        Route::get('/clientes/{account}', [Admin\AccountController::class, 'show'])->name('accounts.show');
        Route::post('/clientes/{account}/acao', [Admin\AccountController::class, 'action'])->name('accounts.action');
        Route::get('/planos', [Admin\PlanController::class, 'index'])->name('plans.index');
        Route::post('/planos', [Admin\PlanController::class, 'store'])->name('plans.store');
        Route::put('/planos/{plan}', [Admin\PlanController::class, 'update'])->name('plans.update');
        Route::get('/faturas', [Admin\InvoiceController::class, 'index'])->name('invoices.index');
        Route::post('/faturas/{invoice}/baixa', [Admin\InvoiceController::class, 'markPaid'])->name('invoices.paid');
        Route::get('/uso', [Admin\DashboardController::class, 'usage'])->name('usage');
        Route::get('/sistema', [Admin\SystemController::class, 'index'])->name('system');
        Route::post('/sistema/backup', [Admin\SystemController::class, 'backup'])->name('system.backup');
        Route::post('/sistema/auditoria/verificar', [Admin\SystemController::class, 'auditVerify'])->middleware('throttle:20,1')->name('system.audit.verify');
        Route::post('/sistema/auditoria/ativar-v2', [Admin\SystemController::class, 'auditActivate'])->middleware('throttle:5,1')->name('system.audit.activate');
        Route::post('/sistema/auditoria/ancora', [Admin\SystemController::class, 'auditAnchorNow'])->middleware('throttle:5,1')->name('system.audit.anchor');
        Route::post('/sistema/auditoria/conferir-ancora', [Admin\SystemController::class, 'auditAnchorCheck'])->middleware('throttle:20,1')->name('system.audit.anchor_check');
        Route::post('/sistema/migrar', [Admin\SystemController::class, 'migrate'])->middleware('throttle:5,1')->name('system.migrate');
        Route::post('/sistema/configuracoes', [Admin\SystemController::class, 'settings'])->name('system.settings');

        // Atualizações pelo painel (permitidas durante o modo de manutenção — ver bootstrap/app.php)
        Route::get('/atualizacoes', [Admin\UpdateController::class, 'index'])->name('updates');
        Route::post('/atualizacoes/enviar', [Admin\UpdateController::class, 'upload'])->name('updates.upload');
        Route::post('/atualizacoes/analisar', [Admin\UpdateController::class, 'analyze'])->name('updates.analyze');
        Route::post('/atualizacoes/excluir', [Admin\UpdateController::class, 'destroy'])->name('updates.destroy');
        Route::post('/atualizacoes/iniciar', [Admin\UpdateController::class, 'start'])->middleware('throttle:10,1')->name('updates.start');
        Route::get('/atualizacoes/execucao/{id}', [Admin\UpdateController::class, 'run'])->where('id', '[0-9a-z]{26}')->name('updates.run');
        Route::post('/atualizacoes/execucao/{id}/passo', [Admin\UpdateController::class, 'step'])->where('id', '[0-9a-z]{26}')->name('updates.step');
        Route::post('/atualizacoes/execucao/{id}/reverter', [Admin\UpdateController::class, 'revert'])->where('id', '[0-9a-z]{26}')->middleware('throttle:5,1')->name('updates.revert');
    });
});
