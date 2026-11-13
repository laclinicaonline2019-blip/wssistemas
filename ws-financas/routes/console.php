<?php

use App\Core\Audit\AuditAnchor;
use App\Core\Audit\ChainVerifier;
use App\Core\Tenancy\TenantContext;
use App\Services\BackupService;
use App\Services\Billing\BillingService;
use App\Services\Health;
use App\Services\PrivacyService;
use App\Services\Reminders;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
| Rotinas — na HostGator um único cron (a cada minuto) roda `php artisan schedule:run`.
| Nada depende de processo permanente: a fila do banco é esvaziada a cada minuto.
*/

Artisan::command('wsf:heartbeat', function () {
    Cache::put('wsf:heartbeat', now()->toIso8601String(), now()->addDay());
})->purpose('Sinal de vida do cron');

Artisan::command('wsf:billing:run', function (BillingService $billing, TenantContext $ctx) {
    $r = $ctx->runAsSystem(fn () => $billing->run());
    $this->info(json_encode($r, JSON_UNESCAPED_UNICODE));
})->purpose('Assinaturas: fim do teste, atrasos, suspensão, cancelamentos e sincronização com o Asaas');

Artisan::command('wsf:retention:run', function (PrivacyService $privacy, TenantContext $ctx) {
    $r = $ctx->runAsSystem(fn () => $privacy->runRetention());
    $this->info(json_encode($r, JSON_UNESCAPED_UNICODE));
})->purpose('LGPD: elimina clientes cancelados após o prazo e anonimiza mensagens antigas');

Artisan::command('wsf:backup {--verificar : também testa a restauração}', function (BackupService $backups, TenantContext $ctx) {
    $ctx->runAsSystem(function () use ($backups) {
        $r = $backups->create();
        $this->info('Backup: '.$r['file'].' ('.number_format($r['bytes'] / 1024, 1, ',', '.').' KB)');
        if ($this->option('verificar')) {
            $v = $backups->verify($r['file']);
            $v['ok'] ? $this->info('Restauração testada: OK') : $this->error('Restauração com problemas: '.implode('; ', $v['problems']));
        }
    });
})->purpose('Backup criptografado do banco (com teste de restauração opcional)');

Artisan::command('wsf:backup:verify {arquivo?}', function (BackupService $backups, TenantContext $ctx) {
    $file = $this->argument('arquivo') ?: $backups->latest();
    if (! $file) {
        $this->error('Nenhum backup encontrado.');

        return 1;
    }
    $v = $ctx->runAsSystem(fn () => $backups->verify($file));
    $v['ok'] ? $this->info('OK: '.basename($file).' restaura corretamente.') : $this->error(implode("\n", $v['problems']));

    return $v['ok'] ? 0 : 1;
})->purpose('Teste de restauração de um backup em banco temporário');

Artisan::command('wsf:backup:restore {arquivo : nome do arquivo em storage/app/backups ou caminho} {--force}', function (BackupService $backups, TenantContext $ctx) {
    $file = (string) $this->argument('arquivo');
    if (! is_file($file)) {
        $file = $backups->dir().'/'.basename($file);
    }
    if (! $this->option('force') && ! $this->confirm('Repor, a partir do backup, os registros que estiverem faltando? Nada é apagado nem sobrescrito.')) {
        return 1;
    }
    $report = $ctx->runAsSystem(fn () => $backups->restore($file));
    $ins = array_sum(array_column($report, 'inseridos'));
    $conf = array_sum(array_column($report, 'conflitos'));
    foreach ($report as $t => $r) {
        if ($r['inseridos'] || $r['conflitos']) {
            $this->line(str_pad($t, 28).'inseridos '.$r['inseridos'].' · já existentes '.$r['existentes'].' · diferentes do backup (mantidos como estão) '.$r['conflitos']);
        }
    }
    $this->info('Restauração sem perda: '.$ins.' registros repostos; '.$conf.' diferenças mantidas como estão (nada apagado ou sobrescrito).');
})->purpose('Repõe registros ausentes a partir de um backup (nunca apaga nem sobrescreve)');

Artisan::command('wsf:audit:verify {--sem-evento : não grava o evento-resumo na cadeia}', function (TenantContext $ctx) {
    $r = $ctx->runAsSystem(function () {
        $v = app(ChainVerifier::class);
        do {
            $r = $v->run(null, null, true, ! $this->option('sem-evento'));
        } while (! $r['done']);

        return $r;
    });
    foreach ($r['summary'] as $st => $n) {
        $this->line(str_pad(ChainVerifier::LABELS[$st] ?? $st, 40).$n);
    }
    foreach ($r['compat'] as $c) {
        $this->line('  compatibilidade #'.$c['id'].' '.$c['action'].' — '.$c['method']);
    }
    foreach ($r['problems'] as $p) {
        $this->error('  #'.$p['id'].' '.$p['action'].' — '.(ChainVerifier::LABELS[$p['status']] ?? $p['status']).' '.($p['detail'] ?? ''));
    }
    $r['ok'] ? $this->info('Auditoria íntegra ('.$r['checked'].' registros). Relatório: '.basename((string) $r['report'])) : $this->error('Auditoria com problemas. Relatório: '.basename((string) $r['report']));

    return $r['ok'] ? 0 : 1;
})->purpose('Verifica a cadeia de auditoria em lotes, com status separados e relatório assinado');

Artisan::command('wsf:audit:anchor', function (TenantContext $ctx) {
    $r = $ctx->runAsSystem(fn () => app(AuditAnchor::class)->create());
    $this->info('Âncora #'.$r['anchor']['last_id'].' gerada'.($r['emailed'] ? ' e enviada para '.implode(', ', $r['emailed']) : '').'.');
})->purpose('Âncora diária da auditoria (cópia em storage e por e-mail)');

Artisan::command('wsf:reminders', function (Reminders $reminders, TenantContext $ctx) {
    $n = $ctx->runAsSystem(fn () => $reminders->send());
    $this->info($n.' lembretes enviados.');
})->purpose('Lembretes de contas do dia no WhatsApp (somente com modelo aprovado e opt-in)');

Artisan::command('wsf:preflight', function (Health $health, TenantContext $ctx) {
    $fail = 0;
    foreach ($ctx->runAsSystem(fn () => $health->checks()) as $c) {
        $this->line(($c['ok'] ? '<info>OK</info>   ' : '<comment>ATENÇÃO</comment> ').$c['item'].' — '.$c['info']);
        $fail += $c['ok'] ? 0 : 1;
    }

    return $fail ? 1 : 0;
})->purpose('Verificação de prontidão para produção');

Schedule::command('wsf:heartbeat')->everyMinute();
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping();
Schedule::command('wsf:billing:run')->hourly()->withoutOverlapping();
Schedule::command('wsf:reminders')->dailyAt('08:05');
Schedule::command('wsf:retention:run')->dailyAt('03:20');
Schedule::command('wsf:backup')->dailyAt('02:10');
Schedule::command('wsf:backup --verificar')->weeklyOn(0, '04:15');
Schedule::command('wsf:audit:verify')->dailyAt('05:30');
Schedule::command('wsf:audit:anchor')->dailyAt('05:50');
