<?php

namespace Tests\Feature\Clinical;

use App\Modules\Clinical\Models\CidCode;
use App\Modules\Clinical\Models\Encounter;
use App\Modules\Identity\Models\User;
use Database\Seeders\ClinicalCatalogSeeder;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ClinicalWebTest extends TestCase
{
    use ClinicalSetup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClinical();
        $this->context()->runAsSystem(fn () => (new ClinicalCatalogSeeder)->run($this->context()));
    }

    public function test_doctor_full_web_flow_start_autosave_finalize_and_addendum(): void
    {
        $this->actingAs($this->doctorUser);
        $this->get(route('workspace'))->assertOk()->assertSee($this->pat->name)->assertSee('Iniciar atendimento');

        $this->post(route('workspace.start', $this->appointmentId))->assertRedirect();
        $encounter = $this->context()->runFor($this->company()->id, fn () => Encounter::query()->firstOrFail());

        $this->get(route('encounters.edit', $encounter))->assertOk()->assertSee('Queixa principal')->assertSee('data-autosave-url', false);
        $this->putJson(route('encounters.autosave', $encounter), ['revision' => 0, 'data' => ['chief_complaint' => 'Cefaleia']])
            ->assertOk()->assertJsonPath('revision', 1);
        $this->putJson(route('encounters.autosave', $encounter), ['revision' => 0, 'data' => ['chief_complaint' => 'x']])->assertStatus(409);

        $cid = CidCode::query()->where('code', 'R51')->firstOrFail();
        $this->get(route('clinical.cid', ['q' => 'cefal']))->assertOk()->assertJsonFragment(['code' => 'R51']);

        $this->post(route('encounters.finalize', $encounter), ['revision' => 1, 'data' => [
            'chief_complaint' => 'Cefaleia', 'conduct' => 'Analgésico e hidratação.', 'diagnoses' => [['cid_code_id' => $cid->id, 'is_primary' => 1]],
        ]])->assertRedirect(route('encounters.show', $encounter));

        $this->get(route('encounters.show', $encounter))->assertOk()->assertSee('Analgésico e hidratação.')->assertSee('Integridade verificada')->assertSee('R51');
        $this->get(route('encounters.edit', $encounter))->assertRedirect(route('encounters.show', $encounter));

        $this->get(route('encounters.addendum', $encounter))->assertOk()->assertSee('Justificativa do adendo')->assertSee('Analgésico e hidratação.');
        $this->post(route('encounters.addendum.store', $encounter), ['reason' => 'Acrescentada orientação de retorno', 'data' => [
            'chief_complaint' => 'Cefaleia', 'conduct' => 'Analgésico e hidratação. Retornar se persistir.',
        ]])->assertRedirect(route('encounters.show', $encounter));
        $this->get(route('encounters.show', $encounter))->assertSee('Adendo')->assertSee('Acrescentada orientação de retorno');

        $this->get(route('patients.show', $this->pat))->assertOk()->assertSee('Atendimentos (prontuário)')->assertSee('1 adendo(s)');
    }

    public function test_clinical_data_is_hidden_from_reception_and_other_doctors_cannot_edit(): void
    {
        $this->actingAs($this->doctorUser)->post(route('workspace.start', $this->appointmentId));
        $encounter = $this->context()->runFor($this->company()->id, fn () => Encounter::query()->firstOrFail());

        $reception = $this->userWithRole($this->company(), 'recepcao');
        $this->actingAs($reception)->get(route('patients.show', $this->pat))->assertOk()->assertDontSee('Atendimentos (prontuário)');
        $this->actingAs($reception)->get(route('encounters.show', $encounter))->assertForbidden();
        $this->actingAs($reception)->get(route('workspace'))->assertForbidden();

        $this->actingAs($this->secondDoctorUser())->get(route('encounters.edit', $encounter))->assertForbidden();
    }

    public function test_triage_medication_and_walk_in_pages(): void
    {
        $this->api($this->clinic['admin'])->postJson("/api/v1/appointments/{$this->appointmentId}/arrive")->assertCreated();
        $nurse = $this->userWithRole($this->company(), 'enfermagem');

        $this->actingAs($nurse)->get(route('triage.index'))->assertOk()->assertSee($this->pat->name)->assertSee('pendente');
        $this->get(route('triage.create', ['patient_id' => $this->pat->id, 'appointment_id' => $this->appointmentId]))->assertOk()->assertSee('Classificação de risco');
        $this->post(route('triage.store'), ['patient_id' => $this->pat->id, 'appointment_id' => $this->appointmentId, 'temperature' => '38,2', 'risk' => 'amarelo'])
            ->assertRedirect(route('triage.index'));
        $this->get(route('triage.index'))->assertSee('Amarelo');

        $this->actingAs($this->clinic['admin'])->get(route('medications.index', ['q' => 'clonazepam']))->assertOk()->assertSee('Clonazepam')->assertSee('B1');

        // Sem unidade selecionada ("todas as filiais"): usa a unidade de cadastro do paciente.
        $this->context()->runFor($this->company()->id, fn () => $this->pat->forceFill(['home_branch_id' => $this->branch()->id])->save());
        $this->actingAs($this->doctorUser)->post(route('workspace.walk_in', $this->pat))->assertRedirect()->assertSessionMissing('error');
        $this->get(route('workspace'))->assertOk()->assertSee('Atendimentos em aberto');
        $this->get(route('patients.show', $this->pat))->assertSee('em andamento')->assertSee('Iniciar atendimento avulso');
    }

    public function test_super_admin_imports_cid_through_platform_page(): void
    {
        $super = $this->context()->runAsSystem(function () {
            $u = new User(['name' => 'Root', 'email' => 'root@example.test', 'password' => self::PASSWORD]);
            $u->is_super_admin = true;
            $u->save();

            return $u;
        });

        $this->actingAs($super)->get(route('platform.catalog.index'))->assertOk()->assertSee('dados de exemplo');
        $file = UploadedFile::fake()->createWithContent('cid.csv', "codigo;descricao\nZ99.9;Dependência de máquina e dispositivo capacitante não especificado\n");
        $this->post(route('platform.catalog.cid'), ['file' => $file, 'version' => 'CID-10'])->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('cid_codes', ['code' => 'Z99.9']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalog.cid_imported']);
    }
}
