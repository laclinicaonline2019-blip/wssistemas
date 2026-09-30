<?php

use App\Http\Controllers\Install\WebInstallerController;
use Illuminate\Support\Facades\Route;

Route::get('instalar', [WebInstallerController::class, 'show'])->name('install.show');
Route::post('instalar', [WebInstallerController::class, 'handle'])->name('install.handle');
