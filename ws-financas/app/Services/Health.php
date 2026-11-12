<?php

namespace App\Services;

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
