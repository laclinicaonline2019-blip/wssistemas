<?php

use App\Http\Controllers\Web\DashboardController;
use App\Modules\Audit\Http\Controllers\Web\AuditWebController;
use App\Modules\Identity\Http\Controllers\Web\AccountController;
use App\Modules\Identity\Http\Controllers\Web\LoginController;
use App\Modules\Identity\Http\Controllers\Web\RoleWebController;
use App\Modules\Identity\Http\Controllers\Web\UserWebController;
use App\Modules\Organization\Http\Controllers\Web\BranchWebController;
use App\Modules\Platform\Http\Controllers\Web\CompanySettingsWebController;
use App\Modules\Platform\Http\Controllers\Web\PlatformWebController;
use App\Modules\Printing\Http\Controllers\PrintTestController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------- Autenticação
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'login'])->middleware('throttle:auth')->name('login.attempt');
    Route::get('two-factor-challenge', [LoginController::class, 'showChallenge'])->name('two-factor.challenge');
    Route::post('two-factor-challenge', [LoginController::class, 'challenge'])->middleware('throttle:auth')->name('two-factor.verify');
});

Route::post('logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

// ---------------------------------------------------------------- Minha conta
Route::middleware('auth')->prefix('conta')->name('account.')->group(function () {
    Route::get('seguranca', [AccountController::class, 'security'])->name('security');
    Route::put('senha', [AccountController::class, 'updatePassword'])->name('password');
    Route::post('2fa/ativar', [AccountController::class, 'enableTwoFactor'])->name('2fa.enable');
    Route::post('2fa/confirmar', [AccountController::class, 'confirmTwoFactor'])->name('2fa.confirm');
    Route::post('2fa/desativar', [AccountController::class, 'disableTwoFactor'])->name('2fa.disable');
});

// ---------------------------------------------------------------- Clínica
Route::middleware(['auth', 'tenant', '2fa.enrolled'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->middleware('permission:dashboard.visualizar')->name('home');
    Route::post('contexto/filial', [DashboardController::class, 'switchBranch'])->name('context.branch');

    Route::prefix('filiais')->name('branches.')->group(function () {
        Route::get('/', [BranchWebController::class, 'index'])->middleware('permission:filial.visualizar')->name('index');
        Route::get('nova', [BranchWebController::class, 'create'])->middleware('permission:filial.criar')->name('create');
        Route::post('/', [BranchWebController::class, 'store'])->middleware('permission:filial.criar')->name('store');
        Route::get('{branch}/editar', [BranchWebController::class, 'edit'])->middleware('permission:filial.editar')->name('edit');
        Route::put('{branch}', [BranchWebController::class, 'update'])->middleware('permission:filial.editar')->name('update');
        Route::patch('{branch}/status', [BranchWebController::class, 'status'])->middleware('permission:filial.desativar')->name('status');
    });

    Route::prefix('usuarios')->name('users.')->group(function () {
        Route::get('/', [UserWebController::class, 'index'])->middleware('permission:usuario.visualizar')->name('index');
        Route::get('novo', [UserWebController::class, 'create'])->middleware('permission:usuario.criar')->name('create');
        Route::post('/', [UserWebController::class, 'store'])->middleware('permission:usuario.criar')->name('store');
        Route::get('{user}/editar', [UserWebController::class, 'edit'])->middleware('permission:usuario.visualizar')->name('edit');
        Route::put('{user}', [UserWebController::class, 'update'])->middleware('permission:usuario.editar')->name('update');
        Route::put('{user}/perfis', [UserWebController::class, 'syncRoles'])->middleware('permission:usuario.perfis')->name('roles');
        Route::post('{user}/bloquear', [UserWebController::class, 'block'])->middleware('permission:usuario.bloquear')->name('block');
        Route::post('{user}/desbloquear', [UserWebController::class, 'unblock'])->middleware('permission:usuario.bloquear')->name('unblock');
    });

    Route::prefix('perfis')->name('roles.')->group(function () {
        Route::get('/', [RoleWebController::class, 'index'])->middleware('permission:perfil.visualizar')->name('index');
        Route::get('novo', [RoleWebController::class, 'create'])->middleware('permission:perfil.gerenciar')->name('create');
        Route::post('/', [RoleWebController::class, 'store'])->middleware('permission:perfil.gerenciar')->name('store');
        Route::get('{role}/editar', [RoleWebController::class, 'edit'])->middleware('permission:perfil.visualizar')->name('edit');
        Route::put('{role}', [RoleWebController::class, 'update'])->middleware('permission:perfil.gerenciar')->name('update');
        Route::delete('{role}', [RoleWebController::class, 'destroy'])->middleware('permission:perfil.gerenciar')->name('destroy');
    });

    Route::get('auditoria', [AuditWebController::class, 'index'])->middleware('permission:auditoria.visualizar')->name('audit.index');
    Route::get('auditoria/exportar', [AuditWebController::class, 'export'])->middleware('permission:auditoria.exportar')->name('audit.export');

    Route::get('empresa', [CompanySettingsWebController::class, 'edit'])->middleware('permission:empresa.visualizar')->name('company.edit');
    Route::put('empresa', [CompanySettingsWebController::class, 'update'])->middleware('permission:empresa.editar')->name('company.update');

    Route::get('impressao/teste/{format}', PrintTestController::class)->middleware('permission:impressao.configurar')->name('print.test');
});

// ---------------------------------------------------------------- Plataforma SaaS
Route::middleware(['auth', 'platform', '2fa.enrolled'])->prefix('plataforma')->name('platform.')->group(function () {
    Route::get('/', [PlatformWebController::class, 'dashboard'])->name('dashboard');
    Route::get('empresas', [PlatformWebController::class, 'companies'])->name('companies.index');
    Route::get('empresas/nova', [PlatformWebController::class, 'createCompany'])->name('companies.create');
    Route::post('empresas', [PlatformWebController::class, 'storeCompany'])->name('companies.store');
    Route::get('empresas/{company}', [PlatformWebController::class, 'showCompany'])->name('companies.show');
    Route::put('empresas/{company}', [PlatformWebController::class, 'updateCompany'])->name('companies.update');
    Route::get('planos', [PlatformWebController::class, 'plans'])->name('plans.index');
    Route::post('planos', [PlatformWebController::class, 'storePlan'])->name('plans.store');
    Route::put('planos/{plan}', [PlatformWebController::class, 'updatePlan'])->name('plans.update');
});
