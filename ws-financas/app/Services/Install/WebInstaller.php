<?php

namespace App\Services\Install;

use App\Core\Support\BusinessRule;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;

/**
 * Instalação pela interface web (sem terminal). Cada operação é um caminho fixo de código:
 * nada do que vem do navegador é executado como comando — só valores validados são gravados no .env.
 */
class WebInstaller
{
    public const REQUIRED_EXTENSIONS = ['pdo_mysql', 'openssl', 'mbstring', 'tokenizer', 'xml', 'ctype', 'fileinfo', 'filter', 'json'];

    public const RECOMMENDED_EXTENSIONS = ['zip' => 'atualizações pelo painel', 'curl' => 'WhatsApp, Asaas e IA', 'sodium' => 'assinatura dos pacotes de atualização',
        'gd' => 'relatórios em PDF', 'intl' => 'formatação', 'opcache' => 'desempenho'];

    public const WRITABLE_DIRS = ['storage', 'storage/app', 'storage/app/private', 'storage/app/backups', 'storage/app/updates', 'storage/framework',
        'storage/framework/cache', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'];

    /** Diretórios internos que nunca podem ser servidos pelo Apache. */
    public const PRIVATE_DIRS = ['app', 'bootstrap', 'config', 'database', 'deploy', 'docs', 'lang', 'resources', 'routes', 'storage', 'vendor', 'tests'];

    public const DENY_HTACCESS = "# Pasta interna: nunca servida pela web\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n";

    public function __construct(private readonly InstallGuard $guard) {}

    /** Caminho real de um diretório relativo (storage pode ficar fora da pasta do projeto). */
    public static function dirPath(string $d): string
    {
        return $d === 'storage' ? storage_path() : (str_starts_with($d, 'storage/') ? storage_path(substr($d, 8)) : base_path($d));
    }

    public function env(): EnvFile
    {
        return EnvFile::forApp();
    }

    /** Bloqueio definitivo: arquivo de instalação OU trava gravada no .env (são necessárias as duas remoções para reabrir). */
    public function locked(): bool
    {
        return self::lockedStatic();
    }

    public static function lockedStatic(): bool
    {
        return is_file(storage_path('app/installed')) || filter_var(env('WSF_INSTALLER_LOCKED', false), FILTER_VALIDATE_BOOL);
    }

    public function httpsOk(Request $request): bool
    {
        return $request->isSecure() || app()->environment('local', 'testing');
    }

    // ------------------------------------------------------------------ requisitos

    /** @return list<array{item:string, ok:bool, level:string, info:string}> level: blocker | warn | info */
    public function requirements(Request $request): array
    {
        $r = [];
        $add = function (string $item, bool $ok, string $level, string $info) use (&$r) {
            $r[] = compact('item', 'ok', 'level', 'info');
        };

        $add('Conexão segura (HTTPS)', $this->httpsOk($request), 'blocker', $request->isSecure() ? 'ativa' : 'ative o SSL (AutoSSL) no cPanel e acesse por https://');
        $add('PHP 8.3 ou superior', PHP_VERSION_ID >= 80300, 'blocker', PHP_VERSION.' — mude em cPanel → MultiPHP Manager');
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $add('Extensão '.$ext, extension_loaded($ext), 'blocker', extension_loaded($ext) ? 'ok' : 'ative em cPanel → Selecionar versão do PHP → Extensões');
        }
        foreach (self::RECOMMENDED_EXTENSIONS as $ext => $why) {
            $add('Extensão '.$ext, extension_loaded($ext) || ($ext === 'opcache' && function_exists('opcache_get_status')), 'warn', $why);
        }
        $add('Arquivo .env', $this->env()->exists(), 'blocker', $this->env()->exists() ? 'encontrado' : 'copie .env.example para .env no Gerenciador de Arquivos');
        $add('.env gravável pelo PHP', $this->env()->writable(), 'blocker', 'permissão 600 ou 644 no arquivo .env');
        foreach (self::WRITABLE_DIRS as $d) {
            $path = self::dirPath($d);
            $ok = is_dir($path) && is_writable($path);
            if (! $ok && in_array($d, ['storage', 'bootstrap/cache'], true)) {
                $add('Pasta '.$d.' gravável', false, 'blocker', 'permissão 755');
            }
        }
        $add('Pastas de armazenamento (storage)', $this->pathsReady(), 'blocker', $this->pathsReady() ? 'prontas' : 'serão criadas automaticamente no próximo passo');

        $docRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '';
        $public = realpath(public_path()) ?: public_path();
        $docOk = $docRoot === '' || $docRoot === $public || app()->environment('testing');
        $add('Raiz do domínio aponta para /public', $docOk, 'blocker', $docOk ? $public : 'a raiz atual é '.$docRoot.' — em cPanel → Domínios, aponte para '.$public.' (senão o .env ficaria exposto)');

        $mem = $this->bytes((string) ini_get('memory_limit'));
        $add('memory_limit ≥ 128M', $mem < 0 || $mem >= 128 * 1024 * 1024, 'warn', (string) ini_get('memory_limit').' — ajuste em cPanel → MultiPHP INI Editor');
        $met = (int) ini_get('max_execution_time');
        $add('max_execution_time ≥ 60s', $met === 0 || $met >= 60, 'warn', $met.'s (o instalador e as atualizações trabalham em etapas curtas)');
        $add('Upload máximo pelo PHP', true, 'info', ini_get('upload_max_filesize').' — pacotes maiores: envie pelo Gerenciador de Arquivos para storage/app/updates/');
        $free = @disk_free_space(base_path());
        $add('Espaço livre ≥ 300 MB', $free === false || $free >= 300 * 1024 * 1024, 'warn', $free === false ? 'não informado' : round($free / 1048576).' MB (atualizações guardam a versão anterior para rollback)');
        $ownerOk = ! function_exists('posix_geteuid') || @fileowner(base_path('artisan')) === posix_geteuid();
        $add('PHP roda com o mesmo usuário dos arquivos', $ownerOk, 'warn', $ownerOk ? 'ok — atualizações pelo painel disponíveis' : 'atualizações pelo painel não poderão trocar os arquivos; use a atualização manual (docs)');

        return $r;
    }

    public function blockers(array $requirements): array
    {
        return array_values(array_filter($requirements, fn ($c) => ! $c['ok'] && $c['level'] === 'blocker' && $c['item'] !== 'Pastas de armazenamento (storage)'));
    }

    public function pathsReady(): bool
    {
        foreach (self::WRITABLE_DIRS as $d) {
            if (! is_dir(self::dirPath($d)) || ! is_writable(self::dirPath($d))) {
                return false;
            }
        }

        return true;
    }

    /** Cria pastas, ajusta permissões e protege diretórios internos e arquivos sensíveis. @return list<string> */
    public function preparePaths(): array
    {
        $log = [];
        foreach (self::WRITABLE_DIRS as $d) {
            $p = self::dirPath($d);
            if (! is_dir($p)) {
                @mkdir($p, 0755, true) ? $log[] = 'criada '.$d : $log[] = 'NÃO foi possível criar '.$d;
            }
            @chmod($p, in_array($d, ['storage/app/backups', 'storage/app/updates'], true) ? 0700 : 0755);
        }
        foreach (self::PRIVATE_DIRS as $d) {
            $p = self::dirPath($d);
            if (is_dir($p) && ! is_file($p.'/.htaccess')) {
                @file_put_contents($p.'/.htaccess', self::DENY_HTACCESS);
                $log[] = 'protegida '.$d;
            }
        }
        if ($this->env()->exists()) {
            @chmod($this->env()->path(), 0600);
        }
        foreach (['packages.php', 'services.php', 'config.php', 'routes-v7.php', 'events.php'] as $f) {
            @unlink(base_path('bootstrap/cache/'.$f));
        }
        foreach (glob(storage_path('framework/views/*.php')) ?: [] as $f) {
            @unlink($f);
        }
        $log[] = 'caches antigos limpos';

        return $log;
    }

    // ------------------------------------------------------------------ chaves

    /** Gera APP_KEY e segredos internos SOMENTE se ainda não existirem (trocar a APP_KEY tornaria dados criptografados ilegíveis). */
    public function ensureKeys(): array
    {
        $env = $this->env();
        $generated = [];
        $set = [];
        if (! $env->get('APP_KEY')) {
            $set['APP_KEY'] = EnvFile::generateAppKey();
            $generated[] = 'APP_KEY';
        }
        foreach (['AUDIT_HMAC_KEY' => 32, 'BACKUP_PASSWORD' => 24, 'WHATSAPP_VERIFY_TOKEN' => 16, 'ASAAS_WEBHOOK_TOKEN' => 24] as $k => $len) {
            if (! $env->get($k)) {
                $set[$k] = bin2hex(random_bytes($len));
                $generated[] = $k;
            }
        }
        if ($set) {
            $env->set($set, $this->guard->dir());
        }
        if (isset($set['APP_KEY'])) {
            config(['app.key' => $set['APP_KEY']]);
            app()->forgetInstance('encrypter');
            Crypt::clearResolvedInstance('encrypter');
        }

        return $generated;
    }

    // ------------------------------------------------------------------ banco

    /** @return array{host:string, port:int, database:string, username:string, password:string} */
    public function validateDbInput(array $in): array
    {
        $env = $this->env();
        $host = trim((string) ($in['db_host'] ?? ''));
        $port = (int) ($in['db_port'] ?? 3306);
        $db = trim((string) ($in['db_database'] ?? ''));
        $user = trim((string) ($in['db_username'] ?? ''));
        $pass = (string) ($in['db_password'] ?? '');
        if ($pass === '' && ! empty($in['keep_password'])) {
            $pass = (string) $env->get('DB_PASSWORD', '');
        }
        if (! preg_match('/^(localhost|[A-Za-z0-9](?:[A-Za-z0-9.\-]{0,251}[A-Za-z0-9])?|\[?[0-9a-fA-F:.]+\]?)$/', $host)) {
            throw new BusinessRule('Servidor do banco inválido (use localhost na HostGator).');
        }
        if ($port < 1 || $port > 65535) {
            throw new BusinessRule('Porta inválida.');
        }
        if (! preg_match('/^[A-Za-z0-9_]{1,64}$/', $db) || ! preg_match('/^[A-Za-z0-9_]{1,80}$/', $user)) {
            throw new BusinessRule('Nome do banco e usuário só podem ter letras, números e _ (ex.: conta_wsfin).');
        }
        EnvFile::encode($pass); // recusa caracteres que não cabem com segurança no .env

        return ['host' => $host, 'port' => $port, 'database' => $db, 'username' => $user, 'password' => $pass];
    }

    /** Conecta, confere versão e privilégios (cria/altera/remove tabelas de teste). */
    public function testDatabase(array $c): array
    {
        try {
            $pdo = new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['database']};charset=utf8mb4", $c['username'], $c['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        } catch (PDOException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);
            throw new BusinessRule(match ($code) {
                1045 => 'Usuário ou senha do banco incorretos.',
                1044, 1049 => 'Banco não encontrado ou o usuário não tem acesso a ele. No cPanel → Bancos de dados MySQL, adicione o usuário ao banco com TODOS os privilégios.',
                2002, 2005 => 'Servidor MySQL não encontrado. Na HostGator use "localhost".',
                default => 'Não foi possível conectar ao MySQL (código '.$code.').',
            });
        }
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $maria = stripos($version, 'mariadb') !== false;
        preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $m);
        $num = isset($m[1]) ? (int) $m[1] * 10000 + (int) $m[2] * 100 + (int) $m[3] : 0;
        if (($maria && $num < 100300) || (! $maria && $num < 50708)) {
            throw new BusinessRule('Versão do banco muito antiga ('.$version.'). Requer MySQL 5.7.8+ ou MariaDB 10.3+.');
        }
        $suffix = bin2hex(random_bytes(3));
        try {
            $pdo->exec("CREATE TABLE wsf_probe_a_$suffix (id INT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE wsf_probe_b_$suffix (id INT PRIMARY KEY, a_id INT, INDEX (a_id), FOREIGN KEY (a_id) REFERENCES wsf_probe_a_$suffix(id)) ENGINE=InnoDB");
            $pdo->exec("ALTER TABLE wsf_probe_b_$suffix ADD COLUMN x VARCHAR(10) NULL");
        } catch (PDOException $e) {
            throw new BusinessRule('O usuário do banco não tem todos os privilégios (CREATE, ALTER, INDEX, REFERENCES, DROP). Ajuste em cPanel → Bancos de dados MySQL → Privilégios.');
        } finally {
            @$pdo->exec("DROP TABLE IF EXISTS wsf_probe_b_$suffix");
            @$pdo->exec("DROP TABLE IF EXISTS wsf_probe_a_$suffix");
        }
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $hasData = in_array('migrations', $tables, true);

        return ['version' => $version, 'tables' => count($tables), 'existing' => $hasData,
            'owners' => $hasData && in_array('users', $tables, true) ? (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_platform_admin = 1')->fetchColumn() : 0];
    }

    public function saveDatabase(array $c): void
    {
        $this->env()->set(['DB_CONNECTION' => 'mysql', 'DB_HOST' => $c['host'], 'DB_PORT' => (string) $c['port'], 'DB_DATABASE' => $c['database'],
            'DB_USERNAME' => $c['username'], 'DB_PASSWORD' => $c['password']], $this->guard->dir());
        $this->useDatabase($c);
    }

    public function useDatabase(?array $c = null): void
    {
        $env = $this->env();
        $c ??= ['host' => $env->get('DB_HOST', 'localhost'), 'port' => (int) $env->get('DB_PORT', '3306'), 'database' => (string) $env->get('DB_DATABASE'),
            'username' => (string) $env->get('DB_USERNAME'), 'password' => (string) $env->get('DB_PASSWORD', '')];
        config(['database.default' => 'mysql', 'database.connections.mysql.host' => $c['host'], 'database.connections.mysql.port' => $c['port'],
            'database.connections.mysql.database' => $c['database'], 'database.connections.mysql.username' => $c['username'], 'database.connections.mysql.password' => $c['password']]);
        DB::purge('mysql');
    }

    // ------------------------------------------------------------------ aplicação

    public function saveApplication(string $url, string $stage): void
    {
        $url = rtrim(trim($url), '/');
        if (! preg_match('#^https://[A-Za-z0-9.\-]+(:\d+)?(/[A-Za-z0-9._\-/]*)?$#', $url) && ! app()->environment('local', 'testing')) {
            throw new BusinessRule('Informe o endereço completo com https:// (ex.: https://teste.wsfinancasemdia.com.br).');
        }
        if (! in_array($stage, ['production', 'homologation'], true)) {
            throw new BusinessRule('Ambiente inválido.');
        }
        $this->env()->set(['APP_URL' => $url, 'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_STAGE' => $stage,
            'SESSION_SECURE_COOKIE' => str_starts_with($url, 'https://') ? 'true' : 'false', 'SESSION_DRIVER' => 'database', 'CACHE_STORE' => 'database', 'QUEUE_CONNECTION' => 'database'],
            $this->guard->dir());
    }

    // ------------------------------------------------------------------ migrações

    /** @return array{ran:int, pending:list<string>} */
    public function migrationStatus(): array
    {
        $this->useDatabase();
        $migrator = app('migrator');
        $files = array_keys($migrator->getMigrationFiles([database_path('migrations')]));
        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];

        return ['ran' => count($ran), 'pending' => array_values(array_diff($files, $ran))];
    }

    /** Executa as migrações pendentes com trava (evita duas execuções simultâneas). */
    public function migrate(): string
    {
        $this->useDatabase();
        $lock = fopen($this->guard->dir().'/migrate.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new BusinessRule('Já existe uma execução de migrações em andamento. Aguarde e recarregue a página.');
        }
        try {
            @set_time_limit(300);
            Artisan::call('migrate', ['--force' => true]);
            $out = Artisan::output();
            (new PlanSeeder)->run(); // não sobrescreve planos existentes

            return trim($out) ?: 'Nada a migrar.';
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    // ------------------------------------------------------------------ proprietário e conclusão

    public function ownerEmail(): ?string
    {
        $this->useDatabase();
        $email = User::query()->where('is_platform_admin', true)->value('email');

        return $email ? preg_replace('/(?<=.).(?=[^@]*@)/', '•', $email) : null;
    }

    public function createOwner(string $name, string $email, string $password): User
    {
        $this->useDatabase();
        if (User::query()->where('is_platform_admin', true)->exists()) {
            throw new BusinessRule('Já existe um proprietário — o instalador não cria outro (proteção contra reinstalação não autorizada).');
        }

        return User::query()->create(['name' => trim($name), 'email' => mb_strtolower(trim($email)), 'password' => $password, 'is_platform_admin' => true, 'status' => 'active']);
    }

    public function finish(): array
    {
        $this->env()->set(['WSF_INSTALLER_LOCKED' => 'true', 'WSF_INSTALLED_AT' => now()->format('Y-m-d\TH:i:sP')], $this->guard->dir());
        file_put_contents(storage_path('app/installed'), json_encode(['at' => now()->toIso8601String(), 'version' => config('app.version'), 'via' => 'web']));
        @chmod(storage_path('app/installed'), 0600);
        @chmod($this->env()->path(), 0600);
        foreach (['config:clear', 'route:clear', 'view:clear'] as $cmd) {
            try {
                Artisan::call($cmd);
            } catch (\Throwable) {
            }
        }
        $this->guard->destroyAll();
        $env = $this->env();
        $php = PHP_BINARY && ! str_contains(PHP_BINARY, 'fpm') ? PHP_BINARY : '/usr/local/bin/ea-php83';

        return [
            'cron' => $php.' '.base_path('artisan').' schedule:run >> /dev/null 2>&1',
            'url' => $env->get('APP_URL'),
            'webhook_whatsapp' => $env->get('APP_URL').'/webhooks/whatsapp',
            'webhook_asaas' => $env->get('APP_URL').'/webhooks/asaas',
            'env_path' => $env->path(),
        ];
    }

    private function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '-1') {
            return -1;
        }
        $n = (int) $v;

        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n,
        };
    }
}
