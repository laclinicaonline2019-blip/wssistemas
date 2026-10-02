<?php

namespace Database\Seeders;

use App\Core\Support\BusinessRuleViolation;
use App\Core\Tenancy\TenantContext;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Doctors\Services\DoctorService;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Identity\Models\User;
use App\Modules\Insurance\Models\Insurer;
use App\Modules\Insurance\Models\PriceItem;
use App\Modules\Insurance\Models\PriceTable;
use App\Modules\Insurance\Models\Procedure;
use App\Modules\Organization\Models\Branch;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\PatientService;
use App\Modules\Payments\Models\PaymentGateway;
use App\Modules\Payments\Models\SplitRule;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\SaasPlan;
use App\Modules\Platform\Services\CompanyProvisioningService;
use App\Modules\Scheduling\Services\AvailabilityService;
use App\Modules\Scheduling\Services\BookingService;
use App\Modules\Scheduling\Services\ScheduleConfigService;
use Faker\Factory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
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

    private ?string $demoInsurerId = null;

    private ?string $demoPlanId = null;

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

        $carla = $doctors->create($admin, ['name' => 'Carla Mendes Cardoso', 'social_name' => 'Dra. Carla Cardiologista', 'crm' => '900001', 'crm_state' => 'SP',
            'user_id' => $users['medico']->id, 'bio' => 'Cardiologista (perfil fictício de demonstração).',
            'specialties' => [['id' => $specialty('Cardiologia'), 'rqe' => '90001'], ['id' => $specialty('Clínica Médica')]], 'branches' => [$matriz->id, $filial->id]]);
        $rafael = $doctors->create($admin, ['name' => 'Rafael Pediatra Demonstração', 'crm' => '900002', 'crm_state' => 'SP',
            'specialties' => [['id' => $specialty('Pediatria')]], 'branches' => [$filial->id]]);
        $beatriz = $doctors->create($admin, ['name' => 'Beatriz Dermatologista Demonstração', 'crm' => '900003', 'crm_state' => 'SP',
            'specialties' => [['id' => $specialty('Dermatologia')]], 'branches' => [$matriz->id]]);

        $this->seedSchedule($matriz, $filial, $carla, $rafael, $beatriz);

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
                'insurances' => $i % 2 ? [['insurer_id' => $this->demoInsurerId, 'plan_id' => $this->demoPlanId, 'insurer_name' => 'Convênio Demonstração (fictício)', 'plan_name' => 'Básico', 'card_number' => 'DEMO'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'valid_until' => now()->addYear()->format('Y-m-d'), 'is_primary' => true]] : [],
            ], confirmDuplicate: true);
        }

        $this->seedAppointments($admin, $matriz, $carla, $beatriz);
    }

    /** Salas, grade semanal (seg–sex), tipos de atendimento e painel da TV. */
    private function seedSchedule(Branch $matriz, Branch $filial, $carla, $rafael, $beatriz): void
    {
        $config = app(ScheduleConfigService::class);
        $r1 = $config->saveRoom(['branch_id' => $matriz->id, 'name' => 'Consultório', 'number' => '01']);
        $r2 = $config->saveRoom(['branch_id' => $matriz->id, 'name' => 'Consultório', 'number' => '02']);
        $r3 = $config->saveRoom(['branch_id' => $filial->id, 'name' => 'Consultório', 'number' => '01']);

        foreach ([1, 2, 3, 4, 5] as $weekday) {
            $config->saveTemplate($carla, ['branch_id' => $matriz->id, 'room_id' => $r1->id, 'weekday' => $weekday, 'start_time' => '08:00', 'end_time' => '12:00', 'slot_minutes' => 30, 'max_patients' => 8, 'max_overbooks' => 2]);
            $config->saveTemplate($beatriz, ['branch_id' => $matriz->id, 'room_id' => $r2->id, 'weekday' => $weekday, 'start_time' => '13:00', 'end_time' => '18:00', 'slot_minutes' => 20, 'max_overbooks' => 1]);
            $config->saveTemplate($rafael, ['branch_id' => $filial->id, 'room_id' => $r3->id, 'weekday' => $weekday, 'start_time' => '08:00', 'end_time' => '12:00', 'slot_minutes' => 20, 'max_patients' => 10, 'max_overbooks' => 2]);
        }

        $config->saveTemplate($carla, ['branch_id' => $filial->id, 'room_id' => $r3->id, 'weekday' => 1, 'start_time' => '14:00', 'end_time' => '17:00', 'slot_minutes' => 30]);

        // Convênios (Fase 9): operadora FICTÍCIA, tabela de valores e procedimento TUSS de consulta.
        $consulta = Procedure::create(['table_code' => '22', 'code' => '10101012', 'name' => 'Consulta em consultório (no horário normal ou preestabelecido)', 'kind' => 'consultation', 'is_sample' => true]);
        $hemograma = Procedure::create(['table_code' => '22', 'code' => '40304361', 'name' => 'Hemograma com contagem de plaquetas ou frações', 'kind' => 'exam', 'is_sample' => true]);
        $insurer = Insurer::create(['name' => 'Convênio Demonstração (fictício)', 'ans_registry' => '999999', 'provider_code' => 'DEMO0001',
            'payment_term_days' => 30, 'notes' => 'Operadora fictícia para demonstração — não envie XML deste convênio.']);
        $this->demoPlanId = $insurer->plans()->create(['name' => 'Básico'])->id;
        $this->demoInsurerId = $insurer->id;
        $table = PriceTable::create(['insurer_id' => $insurer->id, 'name' => 'Tabela demonstração', 'valid_from' => now()->startOfYear()->toDateString()]);
        PriceItem::create(['price_table_id' => $table->id, 'procedure_id' => $consulta->id, 'price_cents' => 12000]);
        PriceItem::create(['price_table_id' => $table->id, 'procedure_id' => $hemograma->id, 'price_cents' => 1500, 'requires_authorization' => true, 'copay_type' => 'percent', 'copay_value' => 3000]);
        $matriz->update(['cnes' => '9999999']);

        foreach ([[$carla, 35000], [$rafael, 28000], [$beatriz, 30000]] as [$doctor, $price]) {
            $config->saveService($doctor, ['name' => 'Consulta', 'price_cents' => $price, 'accepts_insurance' => true, 'procedure_id' => $consulta->id]);
            $config->saveService($doctor, ['name' => 'Retorno', 'price_cents' => 0, 'is_return' => true, 'accepts_insurance' => true, 'procedure_id' => $consulta->id]);
        }

        foreach ([$matriz, $filial] as $branch) {
            $branch->update(['settings' => array_merge($branch->settings ?? [], ['panel' => ['token' => Str::random(40), 'show_name' => 'short', 'sound' => true, 'voice' => true, 'repeat' => 2, 'volume' => 1]])]);
        }
        // Pagamentos (Fase 8): gateway MOCK — identificado como simulação em todas as telas — e regra de repasse.
        PaymentGateway::create(['provider' => 'mock', 'mode' => 'mock', 'name' => 'MOCK (demonstração)',
            'webhook_token' => Str::random(40), 'credentials' => [], 'is_default' => true]);
        foreach ([$carla, $rafael, $beatriz] as $doctor) {
            SplitRule::create(['doctor_id' => $doctor->id, 'type' => 'percent', 'value' => 6000]);
        }
    }

    /** Alguns agendamentos nos próximos horários livres (sempre futuros). */
    private function seedAppointments(User $admin, Branch $matriz, $carla, $beatriz): void
    {
        $availability = app(AvailabilityService::class);
        $booking = app(BookingService::class);
        $patientIds = Patient::query()->orderBy('record_number')->limit(10)->pluck('id');
        $slots = array_merge($availability->next($matriz, $carla, null, 5), $availability->next($matriz, $beatriz, null, 5));

        foreach ($slots as $i => $slot) {
            if (! isset($patientIds[$i])) {
                break;
            }

            try {
                $booking->book($admin, ['doctor_id' => $slot->doctorId, 'branch_id' => $matriz->id, 'patient_id' => $patientIds[$i], 'starts_at' => $slot->start, 'channel' => $i % 2 ? 'phone' : 'reception']);
            } catch (BusinessRuleViolation) {
                // horário tomado por outro seed — ignora
            }
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
