<?php

use App\Models\Quote;
use App\Models\User;
use App\Services\AiAssistant;
use App\Services\CommissionService;
use App\Services\FollowUpService;
use App\Services\QuoteService;
use App\Services\WhatsApp\WhatsAppGateway;
use App\Support\Preflight;
use App\Support\Text;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('pf:expirar-respostas', function (QuoteService $quotes) {
    $this->info($quotes->expireOverdue().' resposta(s) de farmácia marcadas como sem resposta.');
})->purpose('Marca farmácias que não responderam no prazo');

Artisan::command('pf:pos-venda', function (FollowUpService $followUp) {
    $this->info($followUp->runDue().' pergunta(s) de pós-venda enviada(s).');
})->purpose('Pergunta ao cliente se o pedido chegou / se comprou, pede indicação e a nota fiscal');

Artisan::command('pf:fechar-comissoes', function (CommissionService $commissions) {
    $this->info($commissions->generateDue().' fechamento(s) de comissão gerado(s).');
})->purpose('Gera os fechamentos de comissão semanais/mensais vencidos');

Artisan::command('pf:encerrar-paradas', function () {
    $n = Quote::query()->whereIn('status', ['recebida', 'enviada', 'respondida', 'apresentada'])->where('updated_at', '<', now()->subDays(3))
        ->update(['status' => 'perdida', 'lost_reason' => 'Sem retorno do cliente (automático)', 'closed_at' => now()]);
    $this->info("{$n} cotação(ões) sem movimento encerradas.");
})->purpose('Encerra cotações paradas há mais de 3 dias');

// Sinal de vida do agendador (conferido pelo pf:verificar)
Schedule::call(fn () => Cache::forever('pf:cron', now()->toIso8601String()))->everyMinute()->name('pf:heartbeat');
Schedule::command('pf:expirar-respostas')->everyFiveMinutes();
Schedule::command('pf:pos-venda')->everyFiveMinutes()->between('8:00', '21:00');
Schedule::command('pf:fechar-comissoes')->dailyAt('06:10');
Schedule::command('pf:encerrar-paradas')->dailyAt('03:20');

Artisan::command('pf:admin {email} {senha} {--nome=Administrador}', function (string $email, string $senha) {
    User::query()->updateOrCreate(['email' => $email], ['name' => $this->option('nome'), 'role' => 'admin', 'password' => $senha, 'active' => true]);
    $this->info("Administrador {$email} pronto.");
})->purpose('Cria ou redefine um administrador');

Artisan::command('pf:verificar {--testar-ia : Faz uma chamada real à IA}', function () {
    $rows = Preflight::run();
    if ($this->option('testar-ia')) {
        try {
            $ai = app(AiAssistant::class);
            if (! $ai->enabled()) {
                throw new RuntimeException('IA desligada ou sem chave.');
            }
            $items = $ai->extractItems('Bom dia! Preciso de 2 caixas de losartana 50mg genérico e um dorflex');
            $rows[] = [count($items) >= 2 ? 'ok' : 'erro', 'Teste real da IA', json_encode($items, JSON_UNESCAPED_UNICODE)];
        } catch (Throwable $e) {
            $rows[] = ['erro', 'Teste real da IA', $e->getMessage()];
        }
    }
    $icon = ['ok' => 'OK', 'aviso' => 'AVISO', 'erro' => 'ERRO'];
    $this->table(['', 'Verificação', 'Detalhe'], array_map(fn ($r) => [$icon[$r[0]], $r[1], $r[2]], $rows));
    $errors = count(array_filter($rows, fn ($r) => $r[0] === 'erro'));
    $errors ? $this->error("{$errors} item(ns) impedem a produção.") : $this->info('Pronto para produção.');

    return $errors ? 1 : 0;
})->purpose('Confere se o sistema está pronto para produção');

Artisan::command('pf:testar-whatsapp {telefone} {--mensagem=}', function (string $telefone) {
    $gw = app(WhatsAppGateway::class);
    $phone = Text::phone($telefone);
    try {
        $id = $gw->sendText($phone, $this->option('mensagem') ?: '✅ Teste da Pesquisa Farma: o WhatsApp do sistema está funcionando.');
        $this->info('Mensagem enviada para '.$phone.' (id '.($id ?? '—').') pelo provedor '.config('pesquisafarma.whatsapp_driver').'.');
    } catch (Throwable $e) {
        $this->error('Falhou: '.$e->getMessage());

        return 1;
    }
})->purpose('Envia uma mensagem de teste pelo WhatsApp real');
