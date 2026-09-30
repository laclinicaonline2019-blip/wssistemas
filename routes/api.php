<?php

use App\Modules\Audit\Http\Controllers\Api\AuditLogController;
use App\Modules\Identity\Http\Controllers\Api\AuthController;
use App\Modules\Identity\Http\Controllers\Api\RoleController;
use App\Modules\Identity\Http\Controllers\Api\TwoFactorController;
use App\Modules\Identity\Http\Controllers\Api\UserController;
use App\Modules\Organization\Http\Controllers\Api\BranchController;
use App\Modules\Platform\Http\Controllers\Api\CompanySettingsController;
use App\Modules\Platform\Http\Controllers\Api\PlatformCompanyController;
use App\Modules\Platform\Http\Controllers\Api\PlatformHealthController;
use App\Modules\Platform\Http\Controllers\Api\PlatformPlanController;
use Illuminate\Support\Facades\Route;

/*
| API REST v1 — autenticação por token Bearer (Sanctum).
| Toda autorização é validada no backend: autenticação → tenant → permissão.
*/

Route::prefix('v1')->name('api.')->group(function () {

    Route::post('auth/token', [AuthController::class, 'token'])->middleware('throttle:auth')->name('auth.token');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {

        Route::prefix('auth')->name('auth.')->group(function () {
            Route::get('me', [AuthController::class, 'me'])->name('me');
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::post('two-factor/setup', [TwoFactorController::class, 'setup'])->name('2fa.setup');
            Route::post('two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('2fa.confirm');
            Route::post('two-factor/disable', [TwoFactorController::class, 'disable'])->name('2fa.disable');
        });

        // ------------------------------------------------------------ Clínica
        Route::middleware(['tenant', '2fa.enrolled'])->group(function () {
            Route::get('company', [CompanySettingsController::class, 'show'])->middleware('permission:empresa.visualizar')->name('company.show');
            Route::patch('company', [CompanySettingsController::class, 'update'])->middleware('permission:empresa.editar')->name('company.update');

            Route::get('branches', [BranchController::class, 'index'])->middleware('permission:filial.visualizar')->name('branches.index');
            Route::post('branches', [BranchController::class, 'store'])->middleware('permission:filial.criar')->name('branches.store');
            Route::get('branches/{branch}', [BranchController::class, 'show'])->middleware('permission:filial.visualizar')->name('branches.show');
            Route::patch('branches/{branch}', [BranchController::class, 'update'])->middleware('permission:filial.editar')->name('branches.update');
            Route::patch('branches/{branch}/status', [BranchController::class, 'status'])->middleware('permission:filial.desativar')->name('branches.status');

            Route::get('users', [UserController::class, 'index'])->middleware('permission:usuario.visualizar')->name('users.index');
            Route::post('users', [UserController::class, 'store'])->middleware('permission:usuario.criar')->name('users.store');
            Route::get('users/{user}', [UserController::class, 'show'])->middleware('permission:usuario.visualizar')->name('users.show');
            Route::patch('users/{user}', [UserController::class, 'update'])->middleware('permission:usuario.editar')->name('users.update');
            Route::post('users/{user}/block', [UserController::class, 'block'])->middleware('permission:usuario.bloquear')->name('users.block');
            Route::post('users/{user}/unblock', [UserController::class, 'unblock'])->middleware('permission:usuario.bloquear')->name('users.unblock');
            Route::put('users/{user}/roles', [UserController::class, 'syncRoles'])->middleware('permission:usuario.perfis')->name('users.roles');

            Route::get('permissions', [RoleController::class, 'permissions'])->middleware('permission:perfil.visualizar')->name('permissions.index');
            Route::get('roles', [RoleController::class, 'index'])->middleware('permission:perfil.visualizar|usuario.perfis')->name('roles.index');
            Route::post('roles', [RoleController::class, 'store'])->middleware('permission:perfil.gerenciar')->name('roles.store');
            Route::get('roles/{role}', [RoleController::class, 'show'])->middleware('permission:perfil.visualizar')->name('roles.show');
            Route::patch('roles/{role}', [RoleController::class, 'update'])->middleware('permission:perfil.gerenciar')->name('roles.update');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:perfil.gerenciar')->name('roles.destroy');

            Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:auditoria.visualizar')->name('audit.index');
        });

        // ------------------------------------------------ Plataforma (SaaS)
        Route::prefix('platform')->name('platform.')->middleware(['platform', '2fa.enrolled'])->group(function () {
            Route::get('health', [PlatformHealthController::class, 'health'])->name('health');
            Route::get('metrics', [PlatformHealthController::class, 'metrics'])->name('metrics');
            Route::get('companies', [PlatformCompanyController::class, 'index'])->name('companies.index');
            Route::post('companies', [PlatformCompanyController::class, 'store'])->name('companies.store');
            Route::get('companies/{company}', [PlatformCompanyController::class, 'show'])->name('companies.show');
            Route::patch('companies/{company}', [PlatformCompanyController::class, 'update'])->name('companies.update');
            Route::get('plans', [PlatformPlanController::class, 'index'])->name('plans.index');
            Route::post('plans', [PlatformPlanController::class, 'store'])->name('plans.store');
            Route::patch('plans/{plan}', [PlatformPlanController::class, 'update'])->name('plans.update');
        });
    });
});
