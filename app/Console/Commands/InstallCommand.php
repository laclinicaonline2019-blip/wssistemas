<?php

namespace App\Console\Commands;

use App\Core\Health\HealthChecker;
use App\Core\Install\Installer;
use App\Core\Security\PasswordRules;
use App\Core\Validation\Cnpj;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Instalação via terminal (SSH/VPS). Sem SSH, use o instalador web /instalar.
 * Idempotente: pode ser executado novamente com segurança.
 */
class InstallCommand extends Command
{
    protected $signature = 'aivexa:install
        {--super-admin-name=} {--super-admin-email=} {--super-admin-password=}
        {--company-legal-name=} {--company-trade-name=} {--company-document=}
        {--admin-name=} {--admin-email=} {--admin-password=}
        {--skip-company : Não criar a primeira clínica}';

    protected $description = 'Instala e configura o AivexaClínica';

    public function handle(Installer $installer, HealthChecker $health): int
    {
        $this->components->info('AivexaClínica — instalação');

        foreach ($installer->requirements() as $label => $c) {
            $this->components->twoColumnDetail($label, ($c['ok'] ? '<fg=green>OK</>' : ($c['required'] ? '<fg=red>FALHA</>' : '<fg=yellow>AVISO</>')).' '.$c['detail']);
        }

        if (! $installer->requirementsMet()) {
            $this->components->error('Requisitos obrigatórios não atendidos.');

            return self::FAILURE;
        }

        $db = $installer->checkDatabase();
        $this->components->twoColumnDetail('Banco de dados', ($db['ok'] ? '<fg=green>OK</> ' : '<fg=red>FALHA</> ').$db['detail']);

        if (! $db['ok']) {
            return self::FAILURE;
        }

        $this->components->task('Migrations, permissões e planos', function () use ($installer) {
            $installer->prepareDatabase();

            return true;
        });

        if ($installer->hasSuperAdmin()) {
            $this->components->twoColumnDetail('Super Admin', 'já existe');
        } else {
            $user = $installer->createSuperAdmin($this->collect([
                'name' => ['super-admin-name', 'Nome do Super Admin', ['required', 'string', 'max:150']],
                'email' => ['super-admin-email', 'E-mail do Super Admin', ['required', 'email', 'unique:users,email']],
                'password' => ['super-admin-password', 'Senha do Super Admin', ['required', PasswordRules::default()], true],
            ]));
            $this->components->twoColumnDetail('Super Admin', $user->email.' (configure o 2FA no primeiro acesso)');
        }

        if (! $this->option('skip-company') && ! $installer->hasCompany()) {
            $company = $installer->createFirstClinic($this->collect([
                'legal_name' => ['company-legal-name', 'Razão social da clínica', ['required', 'string', 'max:200']],
                'trade_name' => ['company-trade-name', 'Nome fantasia', ['required', 'string', 'max:200']],
                'document' => ['company-document', 'CNPJ', ['required', new Cnpj]],
                'admin_name' => ['admin-name', 'Nome do administrador da clínica', ['required', 'string', 'max:150']],
                'admin_email' => ['admin-email', 'E-mail do administrador da clínica', ['required', 'email', 'unique:users,email']],
                'admin_password' => ['admin-password', 'Senha do administrador da clínica', ['required', PasswordRules::default()], true],
            ]));
            $this->components->twoColumnDetail('Clínica', $company->trade_name.' · matriz + perfis padrão + administrador');
        }

        $installer->markInstalled();

        foreach ($health->run() as $name => $check) {
            $this->components->twoColumnDetail("Serviço: {$name}", ($check['ok'] ? '<fg=green>OK</>' : '<fg=red>FALHA</>').' '.$check['detail']);
        }

        $this->components->info('Instalação concluída. Ambiente: '.config('aivexa.stage'));

        return self::SUCCESS;
    }

    /** @param  array<string, array{0: string, 1: string, 2: array, 3?: bool}>  $spec */
    private function collect(array $spec): array
    {
        $out = [];

        foreach ($spec as $key => $item) {
            [$option, $question, $rules] = $item;
            $secret = $item[3] ?? false;

            do {
                $value = $this->option($option) ?? ($secret ? $this->secret($question) : $this->ask($question));
                $validator = Validator::make([$key => $value], [$key => $rules]);

                if ($validator->fails()) {
                    $this->components->error($validator->errors()->first($key));

                    if ($this->option($option) !== null || ! $this->input->isInteractive()) {
                        throw new RuntimeException("Valor inválido para --{$option}");
                    }
                }
            } while ($validator->fails());

            $out[$key] = $key === 'document' ? preg_replace('/\D/', '', $value) : $value;
        }

        return $out;
    }
}
