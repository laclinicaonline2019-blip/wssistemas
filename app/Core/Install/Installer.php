<?php

namespace App\Core\Install;

use App\Core\Access\PermissionRegistry;
use App\Core\Tenancy\TenantContext;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Services\CompanyProvisioningService;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Instalação/configuração inicial, compartilhada pelo comando `aivexa:install`
 * (SSH/VPS) e pelo instalador web `/instalar` (cPanel sem SSH).
 */
class Installer
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PermissionRegistry $registry,
        private readonly CompanyProvisioningService $provisioning,
    ) {}

    public function lockPath(): string
    {
        return storage_path('app/installed.lock');
    }

    public function isInstalled(): bool
    {
        return File::exists($this->lockPath());
    }

    public function markInstalled(): void
    {
        File::ensureDirectoryExists(dirname($this->lockPath()));
        File::put($this->lockPath(), now()->toIso8601String().PHP_EOL);
    }

    /** @return array<string, array{ok: bool, detail: string, required: bool}> */
    public function requirements(): array
    {
        $driver = config('database.default');
        $pdo = in_array($driver, ['mysql', 'mariadb'], true) ? 'pdo_mysql' : 'pdo_pgsql';
        $checks = [
            'PHP >= 8.3' => [PHP_VERSION_ID >= 80300, PHP_VERSION, true],
        ];

        foreach ([$pdo, 'mbstring', 'openssl', 'sodium', 'fileinfo', 'tokenizer', 'ctype', 'curl', 'intl', 'gd'] as $ext) {
            $checks["Extensão {$ext}"] = [extension_loaded($ext), extension_loaded($ext) ? 'ok' : 'ausente — habilite em "Selecionar versão do PHP" no cPanel', ! in_array($ext, ['intl', 'gd', 'curl'], true)];
        }

        $checks['Hash de senha'] = [true, defined('PASSWORD_ARGON2ID') ? 'argon2id' : 'bcrypt (argon2id indisponível neste PHP)', false];

        foreach (['storage' => storage_path(), 'bootstrap/cache' => base_path('bootstrap/cache')] as $label => $path) {
            $checks["Gravável: {$label}"] = [is_writable($path), is_writable($path) ? 'ok' : 'ajuste permissões para 755/775', true];
        }

        $checks['APP_KEY'] = [(bool) config('app.key'), config('app.key') ? 'definida' : 'ausente', true];
        $checks['APP_DEBUG desligado'] = [! config('app.debug') || ! app()->isProduction(), config('app.debug') ? 'ligado' : 'ok', app()->isProduction()];

        return collect($checks)->map(fn ($c) => ['ok' => $c[0], 'detail' => $c[1], 'required' => $c[2]])->all();
    }

    public function requirementsMet(): bool
    {
        return collect($this->requirements())->every(fn ($c) => $c['ok'] || ! $c['required']);
    }

    /** Gera APP_KEY no .env apenas se estiver vazia (instalação sem SSH). */
    public function ensureAppKey(): bool
    {
        if (config('app.key')) {
            return true;
        }

        $env = base_path('.env');

        if (! is_writable($env)) {
            return false;
        }

        $key = 'base64:'.base64_encode(random_bytes(32));
        $content = File::get($env);
        $content = preg_match('/^APP_KEY=\s*$/m', $content)
            ? preg_replace('/^APP_KEY=\s*$/m', 'APP_KEY='.$key, $content)
            : $content.PHP_EOL.'APP_KEY='.$key.PHP_EOL;
        File::put($env, $content);
        config(['app.key' => $key]);

        return true;
    }

    /** @return array{ok: bool, detail: string} */
    public function checkDatabase(): array
    {
        try {
            $driver = DB::connection()->getDriverName();
            $version = $driver === 'pgsql'
                ? (string) DB::selectOne('SHOW server_version')->server_version
                : (string) DB::selectOne('SELECT VERSION() AS v')->v;

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $isMaria = Str::contains(Str::lower($version), 'mariadb');
                $ok = $isMaria ? version_compare($version, '10.3', '>=') : version_compare($version, '5.7.8', '>=');

                return ['ok' => $ok, 'detail' => ($isMaria ? 'MariaDB ' : 'MySQL ').$version.($ok ? '' : ' — requer MySQL 5.7.8+ ou MariaDB 10.3+')];
            }

            if ($driver === 'pgsql') {
                return ['ok' => version_compare($version, '13', '>='), 'detail' => 'PostgreSQL '.$version];
            }

            return ['ok' => false, 'detail' => "Driver {$driver} não suportado"];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => 'Falha ao conectar: '.Str::limit($e->getMessage(), 180)];
        }
    }

    /** Migrations + catálogo de permissões + planos padrão. */
    public function prepareDatabase(): void
    {
        Artisan::call('migrate', ['--force' => true]);
        $this->registry->sync();
        Artisan::call('db:seed', ['--class' => PlanSeeder::class, '--force' => true]);
    }

    public function hasSuperAdmin(): bool
    {
        return $this->context->runAsSystem(fn () => User::query()->where('is_super_admin', true)->exists());
    }

    public function hasCompany(): bool
    {
        return $this->context->runAsSystem(fn () => Company::query()->exists());
    }

    /** @param  array{name: string, email: string, password: string}  $data */
    public function createSuperAdmin(array $data): User
    {
        return $this->context->runAsSystem(function () use ($data) {
            $user = new User($data);
            $user->is_super_admin = true;
            $user->save();

            return $user;
        });
    }

    /**
     * @param  array{legal_name: string, trade_name: string, document: string, admin_name: string, admin_email: string, admin_password: string}  $data
     */
    public function createFirstClinic(array $data): Company
    {
        return $this->context->runAsSystem(fn () => $this->provisioning->provision(
            company: ['legal_name' => $data['legal_name'], 'trade_name' => $data['trade_name'], 'document' => $data['document'], 'status' => Company::STATUS_ACTIVE],
            headquarters: ['name' => 'Matriz'],
            admin: ['name' => $data['admin_name'], 'email' => $data['admin_email'], 'password' => $data['admin_password']],
        )['company']);
    }
}
