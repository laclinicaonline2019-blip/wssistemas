<?php

use App\Models\Quote;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\QuoteService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('pf:expirar-respostas', function (QuoteService $quotes) {
    $this->info($quotes->expireOverdue().' resposta(s) de farmácia marcadas como sem resposta.');
})->purpose('Marca farmácias que não responderam no prazo');

Artisan::command('pf:fechar-comissoes', function (CommissionService $commissions) {
    $this->info($commissions->generateDue().' fechamento(s) de comissão gerado(s).');
})->purpose('Gera os fechamentos de comissão semanais/mensais vencidos');

Artisan::command('pf:encerrar-paradas', function () {
    $n = Quote::query()->whereIn('status', ['recebida', 'enviada', 'respondida', 'apresentada'])->where('updated_at', '<', now()->subDays(3))
        ->update(['status' => 'perdida', 'lost_reason' => 'Sem retorno do cliente (automático)', 'closed_at' => now()]);
    $this->info("{$n} cotação(ões) sem movimento encerradas.");
})->purpose('Encerra cotações paradas há mais de 3 dias');

Schedule::command('pf:expirar-respostas')->everyFiveMinutes();
Schedule::command('pf:fechar-comissoes')->dailyAt('06:10');
Schedule::command('pf:encerrar-paradas')->dailyAt('03:20');

Artisan::command('pf:admin {email} {senha} {--nome=Administrador}', function (string $email, string $senha) {
    User::query()->updateOrCreate(['email' => $email], ['name' => $this->option('nome'), 'role' => 'admin', 'password' => $senha, 'active' => true]);
    $this->info("Administrador {$email} pronto.");
})->purpose('Cria ou redefine um administrador');
