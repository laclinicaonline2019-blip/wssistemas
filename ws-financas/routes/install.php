<?php

use App\Http\Controllers\InstallController;
use Illuminate\Support\Facades\Route;

// Instalador web: sem o grupo "web" (não depende de APP_KEY, sessão ou banco). Bloqueado após a instalação.
Route::middleware('throttle:install')->group(function () {
    Route::get('/instalar', [InstallController::class, 'show']);
    Route::post('/instalar/codigo', [InstallController::class, 'code'])->middleware('throttle:install-code');
    Route::post('/instalar/{step}', [InstallController::class, 'step'])->where('step', '[a-z]+');
});
