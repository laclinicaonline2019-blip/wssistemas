<?php

namespace App\Http\Controllers\Admin;

use App\Core\Audit\AuditAnchor;
use App\Core\Audit\AuditLogger;
use App\Core\Audit\ChainVerifier;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Services\BackupService;
use App\Services\Health;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Saúde do sistema, backups e políticas (teste grátis, carência, retenção). */
class SystemController extends Controller
{
    public function index(BackupService $backups, Health $health): View
    {
        $files = collect(glob($backups->dir().'/*.wsfbak') ?: [])->sortDesc()->map(fn ($f) => ['name' => basename($f), 'size' => filesize($f), 'at' => filemtime($f)])->values();

        $migrator = app('migrator');
        $pending = array_values(array_diff(array_keys($migrator->getMigrationFiles([database_path('migrations')])), $migrator->getRepository()->getRan()));

        $verifier = app(ChainVerifier::class);

        return view('admin.system', ['checks' => $health->checks(), 'files' => $files, 'pending' => $pending, 'version' => config('app.version'),
            'audit' => ['report' => $verifier->latestReport(), 'pending' => $verifier->pendingState(), 'head' => AuditLogger::headAvailable() ? DB::table('audit_chain_head')->where('id', 1)->first() : null,
                'anchor' => app(AuditAnchor::class)->latestValid(), 'hasKey' => (string) config('wsf.security.audit_hmac_key') !== '', 'total' => AuditLog::query()->count()],
            'settings' => ['trial_days' => config('wsf.billing.trial_days'), 'grace_days' => config('wsf.billing.grace_days'), 'retention' => config('wsf.lgpd.retention_days_after_cancel')]]);
    }

    /** Verificação da cadeia em lotes (retoma de onde parou; limite de tempo por clique). */
    public function auditVerify(Request $request, ChainVerifier $verifier): RedirectResponse
    {
        @set_time_limit(120);
        $r = $verifier->run(null, null, true, true, $request->user()->id);
        if (! $r['done']) {
            return back()->with('ok', 'Verificação em andamento: '.$r['checked'].' de '.$r['total'].' registros. Clique em "Continuar verificação".');
        }
        $compat = count($r['compat']);

        return back()->with($r['ok'] ? 'ok' : 'error', $r['ok']
            ? 'Auditoria íntegra: '.$r['checked'].' registros'.($compat ? ' ('.$compat.' por compatibilidade com o formato v1, detalhados no relatório)' : '').'.'
            : 'Auditoria com problemas: '.collect($r['problems'])->map(fn ($p) => '#'.$p['id'].' '.(ChainVerifier::LABELS[$p['status']] ?? $p['status']))->take(10)->implode('; ').'.');
    }

    /** Ativa o formato v2 — irreversível. Exige senha (a área do proprietário já exige 2FA). */
    public function auditActivate(Request $request, AuditLogger $audit): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password'], 'confirm' => ['required', 'in:ATIVAR V2']],
            ['password.current_password' => 'Senha incorreta.', 'confirm.in' => 'Digite ATIVAR V2 para confirmar.']);
        $audit->activateV2($request->user()->id);

        return back()->with('ok', 'Formato v2 ativado. A partir de agora todos os registros de auditoria usam o formato v2.');
    }

    public function auditAnchorCheck(Request $request, AuditAnchor $anchors): RedirectResponse
    {
        $request->validate(['ancora' => ['required', 'string', 'max:5000']]);
        $text = (string) $request->input('ancora');
        if (preg_match('/-----BEGIN WSF ANCORA-----\s*(.+?)\s*-----END WSF ANCORA-----/s', $text, $m)) {
            $text = $m[1];
        }
        $r = $anchors->checkExternal($text);

        return back()->with($r['valid_signature'] && $r['present'] && $r['total_ok'] ? 'ok' : 'error', $r['message']);
    }

    public function auditAnchorNow(AuditAnchor $anchors): RedirectResponse
    {
        $r = $anchors->create();

        return back()->with('ok', 'Âncora gerada (registro #'.$r['anchor']['last_id'].')'.($r['emailed'] ? ' e enviada para '.implode(', ', $r['emailed']) : '').'.');
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
