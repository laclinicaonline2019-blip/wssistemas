<?php

namespace Tests\Feature\Doctors;

use App\Modules\Doctors\Models\Specialty;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\SaasPlan;
use Tests\TestCase;

class DoctorTest extends TestCase
{
    private function specialty($company, string $name): string
    {
        return $this->context()->runFor($company->id, fn () => Specialty::query()->where('name', $name)->value('id'));
    }

    public function test_new_clinics_get_default_specialties(): void
    {
        ['admin' => $admin] = $this->createClinic();

        $this->api($admin)->getJson('/api/v1/specialties')->assertOk()->assertJsonCount(count(config('specialties')), 'data');
    }

    public function test_creates_doctor_with_specialties_branches_and_user_link(): void
    {
        ['company' => $company, 'admin' => $admin, 'branch' => $branch] = $this->createClinic();
        $medicoUser = $this->userWithRole($company, 'medico');
        $cardio = $this->specialty($company, 'Cardiologia');

        $this->api($admin)->postJson('/api/v1/doctors', [
            'name' => 'Ana Cardio', 'crm' => '123456', 'crm_state' => 'sp', 'cpf' => '529.982.247-25',
            'user_id' => $medicoUser->id,
            'specialties' => [['id' => $cardio, 'rqe' => '9876']],
            'branches' => [$branch->id],
        ])->assertCreated()
            ->assertJsonPath('data.registration', 'CRM 123456/SP')
            ->assertJsonPath('data.specialties.0.rqe', '9876')
            ->assertJsonPath('data.branches.0.id', $branch->id)
            ->assertJsonPath('data.user.id', $medicoUser->id);

        $this->assertDatabaseHas('audit_logs', ['action' => 'doctor.created']);
    }

    public function test_crm_unique_per_company_and_user_link_rules(): void
    {
        ['company' => $a, 'admin' => $adminA, 'branch' => $branchA] = $this->createClinic('A');
        ['admin' => $adminB, 'branch' => $branchB] = $this->createClinic('B');
        $userB = $this->userWithRole($this->context()->runAsSystem(fn () => Company::where('trade_name', 'B')->first()), 'medico');
        $base = ['name' => 'Dr. X', 'crm' => '111', 'crm_state' => 'RJ'];

        $this->api($adminA)->postJson('/api/v1/doctors', $base + ['branches' => [$branchA->id]])->assertCreated();
        $this->api($adminA)->postJson('/api/v1/doctors', $base + ['branches' => [$branchA->id]])->assertJsonValidationErrors('crm');
        $this->api($adminB)->postJson('/api/v1/doctors', $base + ['branches' => [$branchB->id]])->assertCreated();

        // Usuário de outra empresa não pode ser vinculado; o mesmo usuário não pode ter dois médicos.
        $this->api($adminA)->postJson('/api/v1/doctors', ['name' => 'Y', 'crm' => '222', 'crm_state' => 'RJ', 'user_id' => $userB->id])
            ->assertUnprocessable();
        $userA = $this->userWithRole($a, 'medico');
        $this->api($adminA)->postJson('/api/v1/doctors', ['name' => 'Y', 'crm' => '222', 'crm_state' => 'RJ', 'user_id' => $userA->id])->assertCreated();
        $this->api($adminA)->postJson('/api/v1/doctors', ['name' => 'Z', 'crm' => '333', 'crm_state' => 'RJ', 'user_id' => $userA->id])
            ->assertUnprocessable();
        // Especialidade e filial de outra empresa
        $this->api($adminA)->postJson('/api/v1/doctors', ['name' => 'W', 'crm' => '444', 'crm_state' => 'RJ', 'branches' => [$branchB->id]])
            ->assertUnprocessable();
    }

    public function test_plan_limit_for_doctors(): void
    {
        $plan = SaasPlan::create(['code' => 'mini', 'name' => 'Mini', 'price_monthly_cents' => 1, 'price_yearly_cents' => 1, 'limits' => ['max_doctors' => 1]]);
        ['admin' => $admin] = $this->createClinic('Pequena', ['saas_plan_id' => $plan->id]);

        $this->api($admin)->postJson('/api/v1/doctors', ['name' => 'A', 'crm' => '1', 'crm_state' => 'SP'])->assertCreated();
        $this->api($admin)->postJson('/api/v1/doctors', ['name' => 'B', 'crm' => '2', 'crm_state' => 'SP'])
            ->assertUnprocessable()->assertJsonPath('code', 'plan_limit_reached');
    }

    public function test_branch_admin_manages_only_doctors_of_own_branch(): void
    {
        ['company' => $company, 'admin' => $admin, 'branch' => $hq] = $this->createClinic();
        $sul = $this->createBranch($company, 'Sul');
        $gestor = $this->userWithRole($company, 'admin_filial', $sul);

        // admin_filial não tem medico.gerenciar por padrão: concede via perfil customizado na filial
        $roleId = $this->api($admin)->postJson('/api/v1/roles', ['name' => 'Gestor clínico', 'permissions' => ['medico.visualizar', 'medico.gerenciar']])->json('data.id');
        $this->api($admin)->putJson("/api/v1/users/{$gestor->id}/roles", ['roles' => [
            ['role_id' => $this->roleId($company, 'admin_filial'), 'branch_id' => $sul->id],
            ['role_id' => $roleId, 'branch_id' => $sul->id],
        ]])->assertOk();
        $hqDoctor = $this->api($admin)->postJson('/api/v1/doctors', ['name' => 'Matriz', 'crm' => '10', 'crm_state' => 'SP', 'branches' => [$hq->id]])->json('data.id');

        $client = $this->api($gestor);
        $client->postJson('/api/v1/doctors', ['name' => 'Sem filial', 'crm' => '11', 'crm_state' => 'SP'])->assertUnprocessable();
        $client->postJson('/api/v1/doctors', ['name' => 'Na matriz', 'crm' => '12', 'crm_state' => 'SP', 'branches' => [$hq->id]])->assertUnprocessable();
        $own = $client->postJson('/api/v1/doctors', ['name' => 'Do Sul', 'crm' => '13', 'crm_state' => 'SP', 'branches' => [$sul->id]])->assertCreated()->json('data.id');

        $client->patchJson("/api/v1/doctors/{$own}", ['bio' => 'ok'])->assertOk();
        $client->patchJson("/api/v1/doctors/{$hqDoctor}", ['bio' => 'invasão'])->assertForbidden();
        $client->getJson('/api/v1/doctors')->assertOk()->assertJsonCount(2, 'data'); // visualiza o corpo clínico
    }

    public function test_specialty_management_permissions_and_uniqueness(): void
    {
        ['company' => $company, 'admin' => $admin, 'branch' => $branch] = $this->createClinic();

        $this->api($admin)->postJson('/api/v1/specialties', ['name' => 'Medicina do Sono'])->assertCreated();
        $this->api($admin)->postJson('/api/v1/specialties', ['name' => 'Medicina do Sono'])->assertJsonValidationErrors('name');
        $this->api($this->userWithRole($company, 'recepcao', $branch))->postJson('/api/v1/specialties', ['name' => 'X'])->assertForbidden();
    }

    public function test_web_pages(): void
    {
        ['company' => $company, 'admin' => $admin, 'branch' => $branch] = $this->createClinic();
        $this->actingAs($admin);

        $this->get(route('doctors.index'))->assertOk();
        $this->get(route('doctors.create'))->assertOk()->assertSee('Cardiologia');
        $this->post(route('doctors.store'), [
            'name' => 'Web Doctor', 'crm' => '555', 'crm_state' => 'MG',
            'specialty_ids' => [$this->specialty($company, 'Pediatria')], 'rqe' => [$this->specialty($company, 'Pediatria') => '777'],
            'branches' => [$branch->id],
        ])->assertRedirect();
        $this->get(route('doctors.index'))->assertSee('Web Doctor')->assertSee('Pediatria');
        $this->get(route('specialties.index'))->assertOk()->assertSee('Pediatria');
        $this->get(route('search', ['q' => 'Web Doc']))->assertOk()->assertSee('CRM 555/MG');
    }

    public function test_global_search_respects_permissions(): void
    {
        ['company' => $company, 'admin' => $admin] = $this->createClinic();
        $this->api($admin)->postJson('/api/v1/patients', ['name' => 'Paciente Secreto', 'birth_date' => '1990-01-01'])->assertCreated();

        $this->actingAs($this->userWithRole($company, 'financeiro'))
            ->get(route('search', ['q' => 'Secreto']))->assertOk()->assertDontSee('Paciente Secreto');
        $this->actingAs($admin)->get(route('search', ['q' => 'Secreto']))->assertSee('Paciente Secreto');
    }
}
