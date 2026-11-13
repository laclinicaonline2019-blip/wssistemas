<?php

namespace App\Services;

use App\Core\Audit\AuditAnchor;
use App\Core\Audit\AuditLogger;
use App\Core\Audit\ChainVerifier;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Verificação de prontidão — também via `php artisan wsf:preflight`. */
class Health
{
    /** @return list<array{item:string, ok:bool, info:string}> */
    public function checks(): array
    {
        $c = [];
        $c[] = ['item' => 'PHP 8.3+', 'ok' => PHP_VERSION_ID >= 80300, 'info' => PHP_VERSION];
        foreach (['pdo_mysql', 'openssl', 'mbstring', 'gd', 'zip', 'intl', 'sodium'] as $ext) {
            if (in_array($ext, ['gd', 'zip', 'intl', 'sodium'], true) && ! extension_loaded($ext)) {
                $c[] = ['item' => 'Extensão '.$ext, 'ok' => false, 'info' => 'recomendada'];

                continue;
            }
            if (! in_array($ext, ['gd', 'zip', 'intl', 'sodium'], true)) {
                $c[] = ['item' => 'Extensão '.$ext, 'ok' => extension_loaded($ext), 'info' => extension_loaded($ext) ? 'ok' : 'obrigatória'];
            }
        }
        try {
            DB::select('select 1');
            $c[] = ['item' => 'Banco de dados', 'ok' => true, 'info' => DB::connection()->getDriverName()];
        } catch (\Throwable $e) {
            $c[] = ['item' => 'Banco de dados', 'ok' => false, 'info' => 'sem conexão'];
        }
        $beat = Cache::get('wsf:heartbeat');
        $c[] = ['item' => 'Cron (schedule:run a cada minuto)', 'ok' => $beat && now()->diffInMinutes($beat) <= 5, 'info' => $beat ? 'último sinal '.Carbon::parse($beat)->diffForHumans() : 'nunca executou'];
        $c[] = ['item' => 'APP_DEBUG desligado', 'ok' => ! config('app.debug') || ! app()->isProduction(), 'info' => config('app.debug') ? 'ligado' : 'desligado'];
        $c[] = ['item' => 'HTTPS', 'ok' => str_starts_with((string) config('app.url'), 'https://'), 'info' => (string) config('app.url')];
        $c[] = ['item' => 'Senha dos backups (BACKUP_PASSWORD)', 'ok' => (string) config('wsf.backup.password') !== '', 'info' => config('wsf.backup.password') ? 'definida' : 'não definida'];
        $c[] = ['item' => 'Chave da auditoria (AUDIT_HMAC_KEY)', 'ok' => (string) config('wsf.security.audit_hmac_key') !== '', 'info' => config('wsf.security.audit_hmac_key') ? 'definida' : 'usando APP_KEY'];
        try {
            if (AuditLogger::headAvailable()) {
                $h = DB::table('audit_chain_head')->where('id', 1)->first();
                $trg = DB::getDriverName() === 'sqlite' ? 6
                    : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('TRIGGER_NAME', 'like', 'audit\\_%')->count();
                $rep = app(ChainVerifier::class)->latestReport();
                $c[] = ['item' => 'Auditoria: proteções no banco', 'ok' => $trg >= 6, 'info' => $trg.' de 6 triggers · gravação v'.($h->write_version ?? 1)];
                $c[] = ['item' => 'Auditoria: última verificação', 'ok' => $rep && empty($rep['problemas']), 'info' => $rep ? Carbon::parse($rep['gerado_em'])->diffForHumans().(empty($rep['problemas']) ? ' — íntegra' : ' — com problemas') : 'nunca verificada'];
                $anc = app(AuditAnchor::class)->latestValid();
                $c[] = ['item' => 'Auditoria: âncora diária', 'ok' => $anc && now()->diffInHours(Carbon::parse($anc['gerada_em'])) <= 48, 'info' => $anc ? 'última '.Carbon::parse($anc['gerada_em'])->diffForHumans() : 'nenhuma'];
            } else {
                $c[] = ['item' => 'Auditoria: estrutura 1.1.1', 'ok' => false, 'info' => 'migração pendente'];
            }
        } catch (\Throwable $e) {
            $c[] = ['item' => 'Auditoria', 'ok' => false, 'info' => 'erro ao consultar'];
        }
        $c[] = ['item' => 'WhatsApp', 'ok' => config('services.whatsapp.mode') === 'cloud' && config('services.whatsapp.app_secret') && config('services.whatsapp.access_token'),
            'info' => config('services.whatsapp.mode') === 'cloud' ? 'API oficial (Cloud API)' : 'simulado (mock)'];
        $c[] = ['item' => 'Cobrança Asaas', 'ok' => config('services.asaas.mode') === 'asaas' && config('services.asaas.api_key') && config('services.asaas.webhook_token'),
            'info' => config('services.asaas.mode') === 'asaas' ? (config('services.asaas.sandbox') ? 'sandbox' : 'produção') : 'simulada (mock)'];
        $c[] = ['item' => 'Inteligência artificial', 'ok' => (string) config('services.anthropic.key') !== '', 'info' => config('services.anthropic.key') ? config('services.anthropic.model') : 'sem chave (só regras)'];
        $c[] = ['item' => 'E-mail (recuperação de senha)', 'ok' => ! in_array(config('mail.default'), ['log', 'array'], true), 'info' => (string) config('mail.default')];
        $c[] = ['item' => 'Pasta storage gravável', 'ok' => is_writable(storage_path()), 'info' => storage_path()];

        return $c;
    }
}
