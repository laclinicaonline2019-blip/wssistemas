<?php

use App\Modules\Queue\Http\Controllers\Web\PanelController;
use Illuminate\Support\Facades\Route;

/*
| Rotas públicas sem sessão/cookies: painel de chamadas da TV (acesso por token
| secreto da unidade). Não gravam sessão a cada atualização do painel.
*/
Route::get('painel/{token}', [PanelController::class, 'show'])->name('panel.show');
Route::get('painel/{token}/estado', [PanelController::class, 'state'])->name('panel.state');
