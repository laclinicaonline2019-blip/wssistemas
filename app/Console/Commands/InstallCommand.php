<?php

namespace App\Console\Commands;

use App\Core\Access\PermissionRegistry;
use App\Core\Health\HealthChecker;
use App\Core\Security\PasswordRules;
use App\Core\Tenancy\TenantContext;
use App\Core\Validation\Cnpj;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Services\CompanyProvisioningService;
use Database\Seeders\PlanSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Instalação/configuração inicial:
 * requisitos → banco → migrations → catálogo → planos → super admin →
 * primeira clínica (empresa + matriz + admin) → verificação de serviços.
 */
class InstallCommand extends Command
{
    protected $signature = 'aivexa:install
        {--super-admin-name= : Nome do Super Admin}
        {--super-admin-email= : E-mail do Super Admin}
        {--super-admin-password= : Senha do Super Admin (prefira o prompt interativo)}
        {--company-legal-name= : Razão social da primeira clínica}
        {--company-trade-name= : Nome fantasia}
        {--company-document= : CNPJ}
        {--admin-name= : Nome do administrador da clínica}
        {--admin-email= : E-mail do administrador da clínica}
        {--admin-password= : Senha do administrador da clínica}
        {--skip-company : Não criar a primeira clínica}';

    protected $description = 'Instala e configura o AivexaClínica (idempotente)';

    public function handle(TenantContext $context, PermissionRegistry $registry, CompanyProvisioningService $provisioning, HealthChecker $health): int
    {
        $this->components->info('AivexaClínica — instalação');

        // 1. Requisitos
        $requirements = $this->checkRequirements();
        $this->components->task('Requisitos do PHP', fn () => $requirements);

        if (! $requirements) {
            return self::FAILURE;
        }

        if (! config('app.key')) {
            $this->components->error('APP_KEY ausente. Execute: php artisan key:generate');

            return self::FAILURE;
        }

        // 2. Banco
        $dbOk = false;
        $this->components->task('Conexão com o banco de dados (PostgreSQL)', function () use (&$dbOk) {
            try {
                DB::select('select 1');
                $dbOk = DB::connection()->getDriverName() === 'pgsql';
            } catch (Throwable) {
                $dbOk = false;
            }

            return $dbOk;
        });

        if (! $dbOk) {
            $this->components->error('Não foi possível conectar ao PostgreSQL. Verifique DB_* no .env.');

            return self::FAILURE;
        }

        // 3. Migrations
        $this->components->task('Migrations', fn () => $this->callSilently('migrate', ['--force' => true]) === 0);

        // 4. Catálogo de permissões e planos
        $this->components->task('Catálogo de permissões', fn () => (bool) $registry->sync());
        $this->components->task('Planos SaaS padrão', fn () => $this->callSilently('db:seed', ['--class' => PlanSeeder::class, '--force' => true]) === 0);

        // 5. Super Admin
        $context->runAsSystem(function () {
            if (User::query()->where('is_super_admin', true)->exists()) {
                $this->components->twoColumnDetail('Super Admin', 'já existe');

                return;
            }

            $data = $this->collect([
                'name' => ['super-admin-name', 'Nome do Super Admin', ['required', 'string', 'max:150']],
                'email' => ['super-admin-email', 'E-mail do Super Admin', ['required', 'email', 'unique:users,email']],
                'password' => ['super-admin-password', 'Senha do Super Admin', ['required', PasswordRules::default()], true],
            ]);

            $user = new User($data);
            $user->is_super_admin = true;
            $user->save();

            $this->components->twoColumnDetail('Super Admin', $user->email.' (configure o 2FA no primeiro acesso)');
        });

        // 6. Primeira clínica
        if (! $this->option('skip-company') && $context->runAsSystem(fn () => Company::query()->doesntExist())) {
            $c = $this->collect([
                'legal_name' => ['company-legal-name', 'Razão social da clínica', ['required', 'string', 'max:200']],
                'trade_name' => ['company-trade-name', 'Nome fantasia', ['required', 'string', 'max:200']],
                'document' => ['company-document', 'CNPJ', ['required', new Cnpj]],
                'admin_name' => ['admin-name', 'Nome do administrador da clínica', ['required', 'string', 'max:150']],
                'admin_email' => ['admin-email', 'E-mail do administrador da clínica', ['required', 'email', 'unique:users,email']],
                'admin_password' => ['admin-password', 'Senha do administrador da clínica', ['required', PasswordRules::default()], true],
            ]);

            $result = $context->runAsSystem(fn () => $provisioning->provision(
                company: ['legal_name' => $c['legal_name'], 'trade_name' => $c['trade_name'], 'document' => $c['document'], 'status' => Company::STATUS_ACTIVE],
                headquarters: ['name' => 'Matriz'],
                admin: ['name' => $c['admin_name'], 'email' => $c['admin_email'], 'password' => $c['admin_password']],
            ));

            $this->components->twoColumnDetail('Clínica', $result['company']->trade_name.' · matriz + perfis padrão + administrador');
        }

        // 7-10. Permissões de diretório, armazenamento e serviços
        $this->components->task('Diretórios graváveis (storage, cache)', fn () => is_writable(storage_path()) && is_writable(base_path('bootstrap/cache')));

        foreach ($health->run() as $name => $check) {
            $this->components->twoColumnDetail("Serviço: {$name}", ($check['ok'] ? '<fg=green>OK</>' : '<fg=red>FALHA</>').' '.$check['detail']);
        }

        $this->newLine();
        $this->components->info('Instalação concluída. Ambiente: '.config('aivexa.stage'));

        return self::SUCCESS;
    }

    private function checkRequirements(): bool
    {
        $missing = array_filter(['pdo_pgsql', 'mbstring', 'openssl', 'sodium', 'intl', 'gd'], fn ($ext) => ! extension_loaded($ext));

        if (PHP_VERSION_ID < 80300 || $missing) {
            $this->components->error('Requer PHP >= 8.3 e extensões: '.implode(', ', $missing));

            return false;
        }

        return true;
    }

    /**
     * Lê opções ou pergunta interativamente, validando cada valor.
     *
     * @param  array<string, array{0: string, 1: string, 2: array, 3?: bool}>  $spec
     */
    private function collect(array $spec): array
    {
        $out = [];

        foreach ($spec as $key => [$option, $question, $rules]) {
            $secret = $spec[$key][3] ?? false;

            do {
                $value = $this->option($option) ?? ($secret ? $this->secret($question) : $this->ask($question));
                $validator = Validator::make([$key => $value], [$key => $rules]);

                if ($validator->fails()) {
                    $this->components->error($validator->errors()->first($key));

                    if ($this->option($option) !== null || ! $this->input->isInteractive()) {
                        throw new \RuntimeException("Valor inválido para --{$option}");
                    }
                }
            } while ($validator->fails());

            $out[$key] = $value;
        }

        return $out;
    }
}
