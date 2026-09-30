<?php

namespace Database\Seeders;

use App\Core\Tenancy\TenantContext;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Doctors\Services\DoctorService;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Branch;
use App\Modules\Patients\Services\PatientService;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\SaasPlan;
use App\Modules\Platform\Services\CompanyProvisioningService;
use Faker\Factory;
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

                $users = [];
                foreach ($people as [$name, $email, $role, $branchId]) {
                    $user = User::create(['name' => $name, 'email' => $email, 'password' => self::PASSWORD]);
                    RoleAssignment::create(['user_id' => $user->id, 'role_id' => $roles[$role], 'branch_id' => $branchId]);
                    $users[$role] = $user;
                }

                $this->seedDoctorsAndPatients($users, $matriz, $filial);
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

    /** Médicos e pacientes FICTÍCIOS (CPFs gerados algoritmicamente, sem relação com pessoas reais). */
    private function seedDoctorsAndPatients(array $users, Branch $matriz, Branch $filial): void
    {
        $specialty = fn (string $name) => Specialty::query()->where('name', $name)->value('id');
        $doctors = app(DoctorService::class);
        $admin = User::query()->whereHas('roleAssignments', fn ($q) => $q->whereNull('branch_id'))->first();

        $doctors->create($admin, ['name' => 'Carla Mendes Cardoso', 'social_name' => 'Dra. Carla Cardiologista', 'crm' => '900001', 'crm_state' => 'SP',
            'user_id' => $users['medico']->id, 'bio' => 'Cardiologista (perfil fictício de demonstração).',
            'specialties' => [['id' => $specialty('Cardiologia'), 'rqe' => '90001'], ['id' => $specialty('Clínica Médica')]], 'branches' => [$matriz->id, $filial->id]]);
        $doctors->create($admin, ['name' => 'Rafael Pediatra Demonstração', 'crm' => '900002', 'crm_state' => 'SP',
            'specialties' => [['id' => $specialty('Pediatria')]], 'branches' => [$filial->id]]);
        $doctors->create($admin, ['name' => 'Beatriz Dermatologista Demonstração', 'crm' => '900003', 'crm_state' => 'SP',
            'specialties' => [['id' => $specialty('Dermatologia')]], 'branches' => [$matriz->id]]);

        $patients = app(PatientService::class);
        $faker = Factory::create('pt_BR');
        $faker->seed(2026);

        for ($i = 0; $i < 25; $i++) {
            $birth = $faker->dateTimeBetween('-85 years', '-1 year');
            $isMinor = $birth > now()->subYears(18);
            $patients->create($admin, [
                'name' => $faker->firstName().' '.$faker->lastName().' '.$faker->lastName(),
                'cpf' => $this->fakeCpf($i),
                'birth_date' => $birth->format('Y-m-d'),
                'sex' => $faker->randomElement(['F', 'M']),
                'whatsapp' => '1199'.str_pad((string) (1000000 + $i), 7, '0', STR_PAD_LEFT),
                'email' => "paciente{$i}@demo.aivexa.local",
                'city' => 'São Paulo', 'state' => 'SP',
                'home_branch_id' => $i % 3 === 0 ? $filial->id : $matriz->id,
                'contacts' => $isMinor ? [['type' => 'guardian', 'name' => $faker->firstName().' '.$faker->lastName().' '.$faker->lastName(), 'relationship' => 'mãe', 'phone' => '11988887777']] : [],
                'insurances' => $i % 2 ? [['insurer_name' => 'Convênio Demonstração', 'plan_name' => 'Básico', 'card_number' => 'DEMO'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'valid_until' => now()->addYear()->format('Y-m-d'), 'is_primary' => true]] : [],
            ], confirmDuplicate: true);
        }
    }

    /** CPF sintético (dígitos verificadores válidos) gerado apenas para demonstração. */
    private function fakeCpf(int $n): string
    {
        $base = '999'.str_pad((string) (100000 + $n), 6, '0', STR_PAD_LEFT);

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $base[$i] * (($t + 1) - $i);
            }
            $base .= ((10 * $sum) % 11) % 10;
        }

        return $base;
    }
}
