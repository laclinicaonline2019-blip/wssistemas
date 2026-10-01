<?php

namespace Tests\Feature\Clinical;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Clinical\Models\Encounter;
use App\Modules\Clinical\Models\EncounterVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class EncounterTest extends TestCase
{
    use ClinicalSetup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClinical();
    }

    public function test_start_prefills_from_triage_and_autosave_uses_optimistic_revision(): void
    {
        $nurse = $this->userWithRole($this->company(), 'enfermagem');
        $this->api($this->clinic['admin'])->postJson("/api/v1/appointments/{$this->appointmentId}/arrive")->assertCreated();
        $this->api($nurse)->postJson('/api/v1/triages', [
            'patient_id' => $this->pat->id, 'appointment_id' => $this->appointmentId,
            'bp_systolic' => 130, 'bp_diastolic' => 85, 'temperature' => 37.8, 'chief_complaint' => 'Febre', 'risk' => 'verde',
        ])->assertCreated();

        $client = $this->api($this->doctorUser);
        $start = $client->postJson('/api/v1/encounters', ['appointment_id' => $this->appointmentId])->assertCreated()
            ->assertJsonPath('data.draft.chief_complaint', 'Febre');
        $this->assertStringContainsString('PA 130/85', $start->json('data.draft.vital_signs'));
        $id = $start->json('data.id');

        $client->getJson("/api/v1/appointments/{$this->appointmentId}")->assertJsonPath('data.status', 'in_service');
        // Iniciar de novo retoma o mesmo atendimento.
        $client->postJson('/api/v1/encounters', ['appointment_id' => $this->appointmentId])->assertJsonPath('data.id', $id);

        $client->putJson("/api/v1/encounters/{$id}/draft", ['revision' => 0, 'data' => ['history' => 'v1', 'unknown_field' => 'x']])
            ->assertOk()->assertJsonPath('revision', 1);
        $client->putJson("/api/v1/encounters/{$id}/draft", ['revision' => 1, 'data' => ['history' => 'v2']])->assertJsonPath('revision', 2);
        // Outra aba ainda com a revisão 1 não sobrescreve.
        $client->putJson("/api/v1/encounters/{$id}/draft", ['revision' => 1, 'data' => ['history' => 'antigo']])
            ->assertStatus(409)->assertJsonPath('code', 'stale_draft');

        $draft = $client->getJson("/api/v1/encounters/{$id}")->json('data.draft');
        $this->assertSame('v2', $draft['data']['history']);
        $this->assertArrayNotHasKey('unknown_field', $draft['data']);
    }

    public function test_finalize_requires_minimum_content_and_makes_record_immutable(): void
    {
        [$id, $rev] = $this->startEncounter();
        $client = $this->api($this->doctorUser);

        $client->postJson("/api/v1/encounters/{$id}/finalize", ['revision' => $rev, 'data' => ['history' => 'só anamnese']])
            ->assertUnprocessable()->assertJsonPath('code', 'incomplete_record');

        $client->postJson("/api/v1/encounters/{$id}/finalize", ['revision' => $rev, 'data' => $this->completeData()])
            ->assertOk()->assertJsonPath('data.status', 'finalized')->assertJsonPath('data.version', 1);

        $client->getJson("/api/v1/appointments/{$this->appointmentId}")->assertJsonPath('data.status', 'completed');
        $client->putJson("/api/v1/encounters/{$id}/draft", ['revision' => $rev, 'data' => ['history' => 'mudar']])
            ->assertStatus(409)->assertJsonPath('code', 'already_finalized');
        $client->postJson("/api/v1/encounters/{$id}/finalize")->assertStatus(409);

        $version = $this->context()->runFor($this->company()->id, fn () => EncounterVersion::query()->where('encounter_id', $id)->firstOrFail());
        $this->assertNull(DB::table('encounters')->where('id', $id)->value('draft_data'));

        // Camada de aplicação
        $this->context()->runFor($this->company()->id, function () use ($version) {
            try {
                $version->forceFill(['data' => ['chief_complaint' => 'adulterado']])->save();
                $this->fail('Versão finalizada não pode ser alterada.');
            } catch (LogicException) {
            }
            try {
                $version->delete();
                $this->fail('Versão finalizada não pode ser excluída.');
            } catch (LogicException) {
            }
        });
    }

    public function test_database_blocks_changes_to_finalized_versions_when_triggers_are_available(): void
    {
        if (! $this->clinicalTriggersActive()) {
            $this->markTestSkipped('Banco sem privilégio para triggers (hospedagem compartilhada) — coberto pela cadeia HMAC.');
        }

        [$id, $rev] = $this->startEncounter();
        $this->api($this->doctorUser)->postJson("/api/v1/encounters/{$id}/finalize", ['revision' => $rev, 'data' => $this->completeData()])->assertOk();

        $this->expectException(QueryException::class);
        DB::table('encounter_versions')->where('encounter_id', $id)->update(['reason' => 'x']);
    }

    public function test_addendum_keeps_original_and_hash_chain_detects_tampering(): void
    {
        $cid = $this->cid('J02.9', 'Faringite aguda não especificada');
        [$id, $rev] = $this->startEncounter();
        $client = $this->api($this->doctorUser);
        $client->postJson("/api/v1/encounters/{$id}/finalize", ['revision' => $rev, 'data' => $this->completeData(['diagnoses' => [['cid_code_id' => $cid->id]]])])->assertOk();

        $client->postJson("/api/v1/encounters/{$id}/addenda", ['reason' => 'curto', 'data' => $this->completeData()])->assertUnprocessable();
        $client->postJson("/api/v1/encounters/{$id}/addenda", [
            'reason' => 'Resultado do teste rápido de estreptococo recebido após a consulta',
            'data' => $this->completeData(['conduct' => 'Amoxicilina 500 mg 8/8h por 10 dias.', 'diagnoses' => [['cid_code_id' => $cid->id]]]),
        ])->assertCreated()->assertJsonPath('data.version', 2);

        $show = $client->getJson("/api/v1/encounters/{$id}")->assertOk();
        $this->assertSame('Sintomáticos e retorno se piora.', $show->json('data.versions.0.data.conduct'));
        $this->assertSame('Amoxicilina 500 mg 8/8h por 10 dias.', $show->json('data.versions.1.data.conduct'));
        $this->assertSame('addendum', $show->json('data.versions.1.kind'));
        $this->assertSame($show->json('data.versions.0.hash'), DB::table('encounter_versions')->where('encounter_id', $id)->where('version', 2)->value('prev_hash'));
        $show->assertJsonPath('data.integrity.ok', true);

        // Adulteração direta no banco (ex.: alguém com acesso ao phpMyAdmin) é detectada.
        if ($this->clinicalTriggersActive()) {
            if (DB::getDriverName() !== 'pgsql') {
                $this->markTestIncomplete('Triggers ativos no MySQL impedem a simulação de adulteração.');
            }
            DB::statement('SET session_replication_role = replica');
        }
        DB::table('encounter_versions')->where('encounter_id', $id)->where('version', 1)
            ->update(['data' => json_encode($this->completeData(['conduct' => 'Conduta adulterada']))]);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('SET session_replication_role = DEFAULT');
        }

        $client->getJson("/api/v1/encounters/{$id}")->assertJsonPath('data.integrity.ok', false)->assertJsonPath('data.integrity.broken_at', 1);
    }

    public function test_only_the_author_doctor_can_edit_finalize_or_amend(): void
    {
        [$id, $rev] = $this->startEncounter();
        $other = $this->secondDoctorUser();

        $this->api($other)->putJson("/api/v1/encounters/{$id}/draft", ['revision' => $rev, 'data' => ['history' => 'x']])
            ->assertForbidden()->assertJsonPath('code', 'not_author');
        $this->api($other)->postJson("/api/v1/encounters/{$id}/finalize", ['data' => $this->completeData()])->assertForbidden();
        $this->api($other)->postJson('/api/v1/encounters', ['appointment_id' => $this->appointmentId])->assertForbidden()->assertJsonPath('code', 'not_your_patient');

        $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->where('action', 'access.denied')->where('auditable_id', $id)->exists());

        // Administrador sem cadastro de médico não pode registrar atendimento.
        $this->api($this->clinic['admin'])->postJson('/api/v1/encounters', ['appointment_id' => $this->appointmentId])
            ->assertForbidden()->assertJsonPath('code', 'not_a_doctor');
    }

    public function test_diagnoses_snapshot_cid_text_and_have_exactly_one_primary(): void
    {
        $a = $this->cid('I10', 'Hipertensão essencial (primária)');
        $b = $this->cid('E11.9', 'Diabetes mellitus não-insulino-dependente - sem complicações');
        [$id, $rev] = $this->startEncounter();

        $this->api($this->doctorUser)->postJson("/api/v1/encounters/{$id}/finalize", ['revision' => $rev, 'data' => $this->completeData(['diagnoses' => [
            ['cid_code_id' => $a->id], ['cid_code_id' => $b->id, 'is_primary' => true], ['cid_code_id' => '01INVALIDINVALIDINVALID000'],
        ]])])->assertOk();

        $this->context()->runAsSystem(fn () => $a->update(['description' => 'Descrição nova da CID']));

        $rows = DB::table('encounter_diagnoses')->where('encounter_id', $id)->orderBy('code')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['E11.9' => true, 'I10' => false], $rows->mapWithKeys(fn ($r) => [$r->code => (bool) $r->is_primary])->all());
        $this->assertSame('Hipertensão essencial (primária)', $rows->firstWhere('code', 'I10')->description);
    }

    public function test_record_access_requires_permission_and_is_audited_without_clinical_content(): void
    {
        [$id, $rev] = $this->startEncounter();
        $this->api($this->doctorUser)->postJson("/api/v1/encounters/{$id}/finalize", ['revision' => $rev, 'data' => $this->completeData(['history' => 'SEGREDO-CLINICO'])])->assertOk();

        $reception = $this->userWithRole($this->company(), 'recepcao');
        $this->api($reception)->getJson("/api/v1/encounters/{$id}")->assertForbidden();
        $this->api($reception)->getJson('/api/v1/encounters?patient_id='.$this->pat->id)->assertForbidden();

        $nurse = $this->userWithRole($this->company(), 'enfermagem');
        $this->api($nurse)->getJson("/api/v1/encounters/{$id}")->assertOk();
        $this->api($nurse)->putJson("/api/v1/encounters/{$id}/draft", ['revision' => 0, 'data' => []])->assertForbidden();

        $logs = AuditLog::query()->withoutGlobalScopes()->where('auditable_id', $id)->get();
        $this->assertTrue($logs->contains('action', 'medical_record.viewed'));
        $this->assertTrue($logs->contains('action', 'medical_record.finalized'));
        $this->assertStringNotContainsString('SEGREDO-CLINICO', DB::table('audit_logs')->get()->toJson());
    }

    public function test_records_are_isolated_between_clinics(): void
    {
        [$id] = $this->startEncounter();
        $outsider = $this->createClinic('Clínica Beta')['admin'];

        $this->api($outsider)->getJson("/api/v1/encounters/{$id}")->assertNotFound();
        $this->api($outsider)->putJson("/api/v1/encounters/{$id}/draft", ['revision' => 0, 'data' => []])->assertNotFound();
        $this->assertSame(0, $this->context()->runFor($outsider->company_id, fn () => Encounter::query()->count()));
    }

    public function test_walk_in_and_patient_history_list(): void
    {
        $client = $this->api($this->doctorUser);
        $id = $client->postJson('/api/v1/encounters', ['patient_id' => $this->pat->id, 'branch_id' => $this->branch()->id])->assertCreated()->json('data.id');
        $client->postJson("/api/v1/encounters/{$id}/finalize", ['revision' => 0, 'data' => $this->completeData()])->assertOk();

        $client->getJson('/api/v1/encounters?patient_id='.$this->pat->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
    }

    private function clinicalTriggersActive(): bool
    {
        return match (DB::getDriverName()) {
            'pgsql' => DB::table('pg_trigger')->where('tgname', 'encounter_versions_immutable')->exists(),
            default => DB::table('information_schema.triggers')->where('trigger_schema', DB::getDatabaseName())
                ->where('event_object_table', 'encounter_versions')->exists(),
        };
    }
}
