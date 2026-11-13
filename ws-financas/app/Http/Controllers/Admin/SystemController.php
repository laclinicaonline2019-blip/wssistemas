<?php

namespace App\Http\Controllers\Admin;

use App\Core\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Services\BackupService;
use App\Services\Health;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;

/** Saúde do sistema, backups e políticas (teste grátis, carência, retenção). */
class SystemController extends Controller
{
    public function index(BackupService $backups, Health $health): View
    {
        $files = collect(glob($backups->dir().'/*.wsfbak') ?: [])->sortDesc()->map(fn ($f) => ['name' => basename($f), 'size' => filesize($f), 'at' => filemtime($f)])->values();

        $migrator = app('migrator');
        $pending = array_values(array_diff(array_keys($migrator->getMigrationFiles([database_path('migrations')])), $migrator->getRepository()->getRan()));

        return view('admin.system', ['checks' => $health->checks(), 'files' => $files, 'audit' => AuditLogger::verify(), 'pending' => $pending, 'version' => config('app.version'),
            'settings' => ['trial_days' => config('wsf.billing.trial_days'), 'grace_days' => config('wsf.billing.grace_days'), 'retention' => config('wsf.lgpd.retention_days_after_cancel')]]);
    }

    public function backup(BackupService $backups): RedirectResponse
    {
        $r = $backups->create();
        $v = $backups->verify($r['file']);

        return back()->with($v['ok'] ? 'ok' : 'error', $v['ok'] ? 'Backup gerado e restauração testada com sucesso ('.basename($r['file']).').' : 'Backup gerado, mas o teste de restauração falhou: '.implode('; ', $v['problems']));
    }

    /** Após uma atualização manual pelo Gerenciador de Arquivos: aplica migrações pendentes (sem terminal), com backup antes. */
    public function migrate(Request $request, BackupService $backups, AuditLogger $audit): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']], ['password.current_password' => 'Senha incorreta.']);
        @set_time_limit(300);
        $b = $backups->create();
        Artisan::call('down', ['--retry' => 60]);
        try {
            Artisan::call('migrate', ['--force' => true]);
            $out = trim(Artisan::output());
            foreach (['config:clear', 'route:clear', 'view:clear'] as $c) {
                Artisan::call($c);
            }
        } finally {
            Artisan::call('up');
        }
        $audit->log('plataforma.migrar', null, ['backup' => basename($b['file']), 'saida' => mb_substr($out, 0, 500)]);

        return back()->with('ok', 'Migrações aplicadas (backup '.basename($b['file']).' feito antes).');
    }

    public function settings(Request $request, AuditLogger $audit): RedirectResponse
    {
        $d = $request->validate(['trial_days' => ['required', 'integer', 'min:0', 'max:90'], 'grace_days' => ['required', 'integer', 'min:0', 'max:60'], 'retention' => ['required', 'integer', 'min:30', 'max:3650']]);
        PlatformSetting::put('billing.trial_days', (int) $d['trial_days']);
        PlatformSetting::put('billing.grace_days', (int) $d['grace_days']);
        PlatformSetting::put('lgpd.retention_days_after_cancel', (int) $d['retention']);
        $audit->log('plataforma.configuracoes', null, $d);

        return back()->with('ok', 'Configurações salvas.');
    }
}
