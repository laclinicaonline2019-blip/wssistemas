<?php

namespace Database\Seeders;

use App\Core\Tenancy\TenantContext;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Branch;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\SaasPlan;
use App\Modules\Platform\Services\CompanyProvisioningService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Dados DEMONSTRATIVOS e FICTÍCIOS (desenvolvimento/homologação).
 * Nunca executar em produção. Nenhum dado real de paciente é utilizado.
 *
 * Senha de todos os usuários demo: Demo@12345
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Demo@12345';

    public function run(TenantContext $context, CompanyProvisioningService $provisioning): void
    {
        if (app()->isProduction() || config('aivexa.stage') === 'production') {
            throw new RuntimeException('DemoSeeder não pode ser executado em produção.');
        }

        $this->call(DatabaseSeeder::class);

        $context->runAsSystem(function () use ($context, $provisioning) {
            if (! User::query()->where('email', 'superadmin@demo.aivexa.local')->exists()) {
                $super = new User(['name' => 'Super Admin Demo', 'email' => 'superadmin@demo.aivexa.local', 'password' => self::PASSWORD]);
                $super->is_super_admin = true;
                $super->save();
            }

            if (Company::query()->where('slug', 'clinica-demonstracao')->exists()) {
                return;
            }

            $result = $provisioning->provision(
                company: [
                    'legal_name' => 'Clínica Demonstração Ltda (FICTÍCIA)',
                    'trade_name' => 'Clínica Demonstração',
                    'document' => '11222333000181',
                    'email' => 'contato@demo.aivexa.local',
                    'saas_plan_id' => SaasPlan::query()->where('code', 'profissional')->value('id'),
                    'status' => Company::STATUS_ACTIVE,
                ],
                headquarters: ['name' => 'Matriz Centro', 'code' => 'MATRIZ', 'city' => 'São Paulo', 'state' => 'SP'],
                admin: ['name' => 'Ana Administradora', 'email' => 'admin@demo.aivexa.local', 'password' => self::PASSWORD],
            );

            $context->runFor($result['company']->id, function () use ($result) {
                $filial = Branch::create(['name' => 'Filial Zona Sul', 'code' => 'ZSUL', 'city' => 'São Paulo', 'state' => 'SP',
                    'street' => 'Avenida Exemplo', 'number' => '1000', 'district' => 'Bairro Fictício', 'zip_code' => '04000000']);
                $matriz = $result['branch'];
                $roles = Role::query()->pluck('id', 'key');

                $people = [
                    ['Bruno Gestor da Filial', 'gestor.filial@demo.aivexa.local', 'admin_filial', $filial->id],
                    ['Dra. Carla Cardiologista', 'medica@demo.aivexa.local', 'medico', null],
                    ['Diego Recepcionista', 'recepcao@demo.aivexa.local', 'recepcao', $matriz->id],
                    ['Elisa Financeiro', 'financeiro@demo.aivexa.local', 'financeiro', null],
                    ['Fábio Enfermeiro', 'enfermagem@demo.aivexa.local', 'enfermagem', $filial->id],
                ];

                foreach ($people as [$name, $email, $role, $branchId]) {
                    $user = User::create(['name' => $name, 'email' => $email, 'password' => self::PASSWORD]);
                    RoleAssignment::create(['user_id' => $user->id, 'role_id' => $roles[$role], 'branch_id' => $branchId]);
                }
            });
        });

        $this->command?->info('Demo pronta. Senha de todos os usuários: '.self::PASSWORD);
        $this->command?->table(['Perfil', 'E-mail'], [
            ['Super Admin (plataforma)', 'superadmin@demo.aivexa.local'],
            ['Admin da empresa', 'admin@demo.aivexa.local'],
            ['Admin da filial (Zona Sul)', 'gestor.filial@demo.aivexa.local'],
            ['Médica', 'medica@demo.aivexa.local'],
            ['Recepção (Matriz)', 'recepcao@demo.aivexa.local'],
            ['Financeiro', 'financeiro@demo.aivexa.local'],
            ['Enfermagem (Zona Sul)', 'enfermagem@demo.aivexa.local'],
        ]);
    }
}
