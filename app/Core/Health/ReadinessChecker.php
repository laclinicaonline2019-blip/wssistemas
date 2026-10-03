<?php

namespace App\Core\Health;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Prontidão para homologação/produção (Fases 19–20): configuração, rotinas, integrações,
 * segurança e backups. Cada item: ok | aviso | erro, com o que fazer para corrigir.
 * Usado por `php artisan aivexa:preflight` e pela tela da plataforma.
 */
class ReadinessChecker
{
    public const HEARTBEAT_KEY = 'aivexa:scheduler:heartbeat';

    /** @var list<array{area: string, item: string, level: string, detail: string, fix: string}> */
    private array $items = [];

    public function __construct(private readonly HealthChecker $health) {}

    /** @return list<array{area: string, item: string, level: string, detail: string, fix: string}> */
    public function run(): array
    {
        $this->items = [];
        $stage = (string) config('aivexa.stage');
        $live = in_array($stage, ['production', 'homologation'], true);
        $prod = $stage === 'production';

        // ---------------- Ambiente
        $this->add('Ambiente', 'Estágio', 'ok', "APP_STAGE={$stage} · APP_ENV=".app()->environment(), '');
        $this->add('Ambiente', 'Modo debug', config('app.debug') ? ($live ? 'error' : 'warn') : 'ok',
            config('app.debug') ? 'APP_DEBUG=true (mostra detalhes internos em erros)' : 'desligado', 'APP_DEBUG=false no .env');
        $this->add('Ambiente', 'Chave da aplicação', config('app.key') ? 'ok' : 'error', config('app.key') ? 'definida' : 'APP_KEY vazia', 'php artisan key:generate (e guarde a chave em cofre)');
        $https = str_starts_with((string) config('app.url'), 'https://');
        $this->add('Ambiente', 'HTTPS', $https ? 'ok' : ($live ? 'error' : 'warn'), (string) config('app.url'), 'APP_URL com https:// e certificado SSL ativo');
        $this->add('Ambiente', 'Cookies seguros', config('session.secure') || ! $https ? 'ok' : 'warn', config('session.secure') ? 'SESSION_SECURE_COOKIE=true' : 'cookie de sessão sem "Secure"', 'SESSION_SECURE_COOKIE=true');
        $this->add('Ambiente', 'HSTS / forçar HTTPS', config('aivexa.security.hsts') && config('aivexa.security.force_https') ? 'ok' : ($prod ? 'warn' : 'ok'),
            'SECURITY_HSTS='.(config('aivexa.security.hsts') ? 'true' : 'false').' · FORCE_HTTPS='.(config('aivexa.security.force_https') ? 'true' : 'false'), 'SECURITY_HSTS=true e FORCE_HTTPS=true em produção');
        $this->add('Ambiente', 'Token do instalador', config('aivexa.install_token') ? ($live ? 'error' : 'warn') : 'ok', config('aivexa.install_token') ? 'INSTALL_TOKEN ainda definido' : 'removido', 'Apague INSTALL_TOKEN do .env após instalar');
        $this->add('Ambiente', 'PHP', version_compare(PHP_VERSION, '8.3.0', '>=') ? 'ok' : 'error', PHP_VERSION, 'PHP 8.3+ (HostGator: cPanel → MultiPHP Manager; VPS: docs/VPS.md)');
        $missing = array_values(array_filter(['mbstring', 'intl', 'gd', 'zip', 'sodium', 'openssl', 'fileinfo', 'dom', 'curl', 'pdo_'.(DB::getDriverName() === 'pgsql' ? 'pgsql' : 'mysql')], fn ($e) => ! extension_loaded($e)));
        $this->add('Ambiente', 'Extensões PHP', $missing ? 'error' : 'ok', $missing ? 'faltando: '.implode(', ', $missing) : 'todas presentes', 'Ative as extensões (HostGator: cPanel → Select PHP Version; VPS: apt install php8.3-…)');
        $this->add('Ambiente', '.env fora da pasta pública', file_exists(public_path('.env')) ? 'error' : 'ok', file_exists(public_path('.env')) ? 'public/.env existe!' : 'ok', 'Remova public/.env; o .env fica fora da raiz pública');

        // ---------------- Banco, rotinas, armazenamento
        foreach ($this->health->run() as $name => $c) {
            $this->add('Infraestrutura', ['database' => 'Banco de dados', 'migrations' => 'Migrations', 'cache' => 'Cache', 'storage' => 'Armazenamento', 'audit' => 'Auditoria', 'queue' => 'Fila'][$name] ?? $name,
                $c['ok'] ? 'ok' : 'error', $c['detail'], $name === 'queue' ? 'Configure o cron do schedule:run a cada minuto' : ($name === 'migrations' ? 'php artisan migrate --force' : 'Veja storage/logs'));
        }
        $beat = Cache::get(self::HEARTBEAT_KEY);
        $fresh = $beat && now()->diffInMinutes(CarbonImmutable::parse($beat)) <= 5;
        $this->add('Infraestrutura', 'Cron (agendador)', $fresh ? 'ok' : ($live ? 'error' : 'warn'), $beat ? 'último sinal: '.CarbonImmutable::parse($beat)->timezone('America/Sao_Paulo')->format('d/m H:i') : 'nunca rodou',
            'Cron a cada minuto com artisan schedule:run (HostGator: cPanel → Cron Jobs; VPS: /etc/cron.d/aivexa)');
        $free = @disk_free_space(storage_path());
        $this->add('Infraestrutura', 'Espaço em disco', $free === false || $free > 1073741824 ? 'ok' : 'warn', $free === false ? 'não informado pela hospedagem' : round($free / 1073741824, 1).' GB livres', 'Libere espaço ou amplie o plano');
        $mail = (string) config('mail.default');
        $this->add('Infraestrutura', 'E-mail', in_array($mail, ['log', 'array'], true) ? ($live ? 'error' : 'warn') : 'ok', "MAIL_MAILER={$mail}", 'Configure o SMTP no .env (conta de e-mail do domínio)');

        // ---------------- Integrações
        $this->integrations($prod);

        // ---------------- Segurança
        $this->add('Segurança', 'Proxy/WAF', config('aivexa.security.trusted_proxies') ? 'ok' : 'warn', config('aivexa.security.trusted_proxies') ? 'TRUSTED_PROXIES='.config('aivexa.security.trusted_proxies') : 'sem Cloudflare/WAF na frente', 'Cloudflare + TRUSTED_PROXIES=cloudflare (docs/SECURITY.md)');
        $this->add('Segurança', 'Antivírus de uploads', config('aivexa.security.scanner.driver') === 'clamav' ? 'ok' : 'warn',
            config('aivexa.security.scanner.driver') === 'clamav' ? 'ClamAV + verificações próprias' : 'só verificações próprias (normal na hospedagem compartilhada)', 'Em VPS: instale ClamAV e use FILE_SCANNER=clamav');
        $this->add('Segurança', 'Senhas vazadas', config('aivexa.security.password_breach_check') ? 'ok' : 'warn', config('aivexa.security.password_breach_check') ? 'verificação ativa' : 'PASSWORD_BREACH_CHECK=false', 'PASSWORD_BREACH_CHECK=true');
        $noTwoFa = DB::table('users')->where('is_super_admin', true)->whereNull('two_factor_confirmed_at')->whereNull('deleted_at')->count();
        $this->add('Segurança', '2FA do super admin', $noTwoFa ? ($live ? 'error' : 'warn') : 'ok', $noTwoFa ? "{$noTwoFa} super admin(s) sem 2FA" : 'todos com 2FA', 'Ative o 2FA em Minha conta');
        $demo = DB::table('companies')->where('slug', 'clinica-demonstracao')->whereNull('deleted_at')->exists() || DB::table('users')->where('email', 'like', '%@demo.aivexa.local')->exists();
        $this->add('Segurança', 'Dados de demonstração', $demo ? ($prod ? 'error' : 'warn') : 'ok', $demo ? 'há empresa/usuários de demonstração' : 'nenhum', 'Instale a produção do zero (sem DemoSeeder)');

        // ---------------- Backups
        $last = collect(glob(storage_path('app/backups/*.sql.gz*')) ?: [])->map(fn ($f) => filemtime($f))->max();
        $this->add('Backup', 'Backup recente', $last && $last > time() - 26 * 3600 ? 'ok' : ($live ? 'error' : 'warn'),
            $last ? 'último: '.date('d/m/Y H:i', $last) : 'nenhum backup do sistema', 'php artisan aivexa:backup (o cron faz diariamente) + cópia fora do servidor');

        return $this->items;
    }

    public function summary(array $items): array
    {
        return ['ok' => count(array_filter($items, fn ($i) => $i['level'] === 'ok')), 'warn' => count(array_filter($items, fn ($i) => $i['level'] === 'warn')),
            'error' => count(array_filter($items, fn ($i) => $i['level'] === 'error'))];
    }

    private function integrations(bool $prod): void
    {
        try {
            $gw = DB::table('payment_gateways')->where('is_active', true)->selectRaw('mode, COUNT(*) AS qty')->groupBy('mode')->pluck('qty', 'mode')->all();
            $test = ($gw['sandbox'] ?? 0) + ($gw['mock'] ?? 0);
            $this->add('Integrações', 'Pagamentos (clínicas)', $prod && $test ? 'warn' : 'ok', $gw ? collect($gw)->map(fn ($q, $m) => strtoupper($m).": {$q}")->implode(' · ') : 'nenhum gateway ativo',
                'Em produção, troque SANDBOX/MOCK pelas credenciais de produção de cada clínica');
            $wa = DB::table('messaging_channels')->where('is_active', true)->get(['provider', 'mode']);
            $this->add('Integrações', 'WhatsApp (clínicas)', $prod && $wa->contains(fn ($c) => $c->mode !== 'production') ? 'warn' : 'ok',
                $wa->isEmpty() ? 'nenhum canal ativo' : $wa->groupBy(fn ($c) => $c->provider.'/'.$c->mode)->map->count()->map(fn ($q, $k) => "{$k}: {$q}")->implode(' · '), 'Canais em teste/MOCK não falam com pacientes reais');
            $ai = DB::table('ai_configs')->where('is_active', true)->get(['provider', 'api_key']);
            $noKey = $ai->filter(fn ($c) => $c->provider !== 'mock' && ! $c->api_key && ! config('services.'.($c->provider === 'claude' ? 'anthropic' : 'openai').'.key'))->count();
            $this->add('Integrações', 'IA (clínicas)', $noKey ? 'error' : ($prod && $ai->contains('provider', 'mock') ? 'warn' : 'ok'),
                $ai->isEmpty() ? 'nenhuma clínica com IA ativa' : $ai->groupBy('provider')->map->count()->map(fn ($q, $k) => "{$k}: {$q}")->implode(' · ').($noKey ? " · {$noKey} sem chave" : ''), 'Informe a chave da API (clínica ou ANTHROPIC_API_KEY/OPENAI_API_KEY)');
            $billing = (string) config('billing.provider');
            $this->add('Integrações', 'Cobrança da plataforma', $billing === 'asaas' ? (config('billing.asaas.webhook_token') && config('billing.asaas.api_key') ? 'ok' : 'error') : ($prod ? 'warn' : 'ok'),
                $billing === 'asaas' ? 'ASAAS '.(config('billing.asaas.sandbox') ? 'SANDBOX' : 'PRODUÇÃO') : 'MOCK (nada é cobrado)', 'PLATFORM_BILLING_PROVIDER=asaas, chave e token do webhook');
        } catch (Throwable $e) {
            report($e);
            $this->add('Integrações', 'Leitura das integrações', 'error', 'falhou (ver logs)', 'php artisan migrate --force');
        }
    }

    private function add(string $area, string $item, string $level, string $detail, string $fix): void
    {
        $this->items[] = compact('area', 'item', 'level', 'detail', 'fix');
    }
}
