<?php

namespace App\Http\Controllers\Admin;

use App\Core\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Services\BackupService;
use App\Services\Health;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Saúde do sistema, backups e políticas (teste grátis, carência, retenção). */
class SystemController extends Controller
{
    public function index(BackupService $backups, Health $health): View
    {
        $files = collect(glob($backups->dir().'/*.wsfbak') ?: [])->sortDesc()->map(fn ($f) => ['name' => basename($f), 'size' => filesize($f), 'at' => filemtime($f)])->values();

        return view('admin.system', ['checks' => $health->checks(), 'files' => $files, 'audit' => AuditLogger::verify(),
            'settings' => ['trial_days' => config('wsf.billing.trial_days'), 'grace_days' => config('wsf.billing.grace_days'), 'retention' => config('wsf.lgpd.retention_days_after_cancel')]]);
    }

    public function backup(BackupService $backups): RedirectResponse
    {
        $r = $backups->create();
        $v = $backups->verify($r['file']);

        return back()->with($v['ok'] ? 'ok' : 'error', $v['ok'] ? 'Backup gerado e restauração testada com sucesso ('.basename($r['file']).').' : 'Backup gerado, mas o teste de restauração falhou: '.implode('; ', $v['problems']));
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
