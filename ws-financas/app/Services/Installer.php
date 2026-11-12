<?php

namespace App\Services;

use App\Core\Support\BusinessRule;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PDO;

/** Instalação (web e CLI): .env, chave, migrações, planos e proprietário. */
class Installer
{
    public function installed(): bool
    {
        return file_exists(storage_path('app/installed'));
    }

    public function requirements(): array
    {
        return [
            ['item' => 'PHP 8.3 ou superior', 'ok' => PHP_VERSION_ID >= 80300, 'info' => PHP_VERSION],
            ['item' => 'pdo_mysql', 'ok' => extension_loaded('pdo_mysql'), 'info' => ''],
            ['item' => 'openssl', 'ok' => extension_loaded('openssl'), 'info' => ''],
            ['item' => 'mbstring', 'ok' => extension_loaded('mbstring'), 'info' => ''],
            ['item' => 'Pasta storage gravável', 'ok' => is_writable(storage_path()), 'info' => ''],
            ['item' => 'Arquivo .env gravável', 'ok' => is_writable(base_path()) || is_writable(base_path('.env')), 'info' => ''],
        ];
    }

    /** @return array{admin_email:string, cron:string, webhook_whatsapp:string, webhook_asaas:string} */
    public function install(array $d): array
    {
        try {
            new PDO('mysql:host='.$d['db_host'].';port='.$d['db_port'].';dbname='.$d['db_database'], $d['db_username'], (string) ($d['db_password'] ?? ''), [PDO::ATTR_TIMEOUT => 5]);
        } catch (\PDOException $e) {
            throw new BusinessRule('Não consegui conectar ao banco MySQL com esses dados. Confira nome do banco, usuário e senha (no cPanel eles têm o prefixo da conta).');
        }

        $env = file_exists(base_path('.env')) ? (string) file_get_contents(base_path('.env')) : (string) file_get_contents(base_path('.env.example'));
        $set = [
            'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => rtrim($d['app_url'], '/'), 'APP_STAGE' => $d['stage'],
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => $d['db_host'], 'DB_PORT' => (string) $d['db_port'], 'DB_DATABASE' => $d['db_database'],
            'DB_USERNAME' => $d['db_username'], 'DB_PASSWORD' => (string) ($d['db_password'] ?? ''),
            'AUDIT_HMAC_KEY' => bin2hex(random_bytes(32)), 'BACKUP_PASSWORD' => bin2hex(random_bytes(24)),
            'WHATSAPP_VERIFY_TOKEN' => bin2hex(random_bytes(16)), 'ASAAS_WEBHOOK_TOKEN' => bin2hex(random_bytes(24)),
            'SESSION_SECURE_COOKIE' => str_starts_with($d['app_url'], 'https://') ? 'true' : 'false',
        ];
        foreach ($set as $k => $v) {
            $line = $k.'='.(preg_match('/[\s#"\'$]/', $v) ? '"'.addcslashes($v, '"\\$').'"' : $v);
            $env = preg_match('/^'.$k.'=.*$/m', $env) ? preg_replace('/^'.$k.'=.*$/m', str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $env) : $env."\n".$line;
        }
        file_put_contents(base_path('.env'), $env);
        @chmod(base_path('.env'), 0600);

        config(['app.key' => $set['APP_KEY'], 'database.default' => 'mysql', 'database.connections.mysql.host' => $d['db_host'], 'database.connections.mysql.port' => $d['db_port'],
            'database.connections.mysql.database' => $d['db_database'], 'database.connections.mysql.username' => $d['db_username'], 'database.connections.mysql.password' => (string) ($d['db_password'] ?? ''),
            'wsf.security.audit_hmac_key' => $set['AUDIT_HMAC_KEY']]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        DB::purge('mysql');

        $this->finish($d['admin_name'], $d['admin_email'], $d['admin_password']);

        return ['admin_email' => $d['admin_email'], 'cron' => '* * * * * /usr/local/bin/php '.base_path('artisan').' schedule:run >> /dev/null 2>&1',
            'webhook_whatsapp' => rtrim($d['app_url'], '/').'/webhooks/whatsapp', 'webhook_asaas' => rtrim($d['app_url'], '/').'/webhooks/asaas',
            'verify_token' => $set['WHATSAPP_VERIFY_TOKEN'], 'asaas_token' => $set['ASAAS_WEBHOOK_TOKEN']];
    }

    /** Migrações, planos e proprietário (compartilhado com `php artisan wsf:install`). */
    public function finish(string $name, string $email, string $password): User
    {
        Artisan::call('migrate', ['--force' => true]);
        (new PlanSeeder)->run();
        $user = User::query()->updateOrCreate(['email' => mb_strtolower($email)], ['name' => $name, 'password' => $password, 'is_platform_admin' => true, 'status' => 'active']);
        if (! is_dir(storage_path('app'))) {
            mkdir(storage_path('app'), 0755, true);
        }
        file_put_contents(storage_path('app/installed'), now()->toIso8601String());

        return $user;
    }
}
