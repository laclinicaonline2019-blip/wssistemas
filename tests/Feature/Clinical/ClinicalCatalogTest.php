<?php

namespace Tests\Feature\Clinical;

use App\Modules\Clinical\Models\CidCode;
use App\Modules\Clinical\Models\Medication;
use App\Modules\Clinical\Models\Triage;
use App\Modules\Clinical\Services\CidService;
use App\Modules\Clinical\Services\MedicationService;
use Database\Seeders\ClinicalCatalogSeeder;
use LogicException;
use Tests\TestCase;

class ClinicalCatalogTest extends TestCase
{
    use ClinicalSetup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClinical();
    }

    public function test_cid_import_reads_datasus_csv_in_latin1_and_updates_without_duplicating(): void
    {
        $csv = "SUBCAT;CLASSIF;RESTRSEXO;CAUSAOBITO;DESCRICAO;DESCRABREV;REFCAT;EXCLUIDOS;\n"
            ."A000;;;;Cólera devida a Vibrio cholerae 01, biótipo cholerae;A00.0 Colera dev Vibrio cholerae 01 biot cholerae;;;\n"
            ."I10;;;;Hipertensão essencial (primária);I10 Hipertensao essencial;;;\n"
            ."N40;;M;;Hiperplasia da próstata;N40 Hiperplasia da prostata;;;\n"
            .";;;;linha sem código;;;;\n";
        $path = tempnam(sys_get_temp_dir(), 'cid');
        file_put_contents($path, mb_convert_encoding($csv, 'ISO-8859-1', 'UTF-8'));

        $stats = $this->context()->runAsSystem(fn () => app(CidService::class)->import($path));
        $this->assertSame(['created' => 3, 'updated' => 0, 'skipped' => 1], $stats);

        $again = $this->context()->runAsSystem(fn () => app(CidService::class)->import($path));
        $this->assertSame(0, $again['created']);

        $cholera = CidCode::query()->where('code', 'A00.0')->firstOrFail();
        $this->assertStringStartsWith('Cólera', $cholera->description);
        $this->assertSame('M', CidCode::query()->where('code', 'N40')->value('sex_restriction'));

        // Busca sem acento, por código parcial e por palavra.
        $found = $this->api($this->doctorUser)->getJson('/api/v1/cid?q=colera')->assertOk()->json('data');
        $this->assertSame('A00.0', $found[0]['code']);
        $this->assertSame('I10', $this->api($this->doctorUser)->getJson('/api/v1/cid?q=i10')->json('data.0.code'));
        unlink($path);
    }

    public function test_cid_favorites_come_first_in_search(): void
    {
        $this->context()->runAsSystem(fn () => (new ClinicalCatalogSeeder)->run($this->context()));
        $j06 = CidCode::query()->where('code', 'J06.9')->firstOrFail();

        $before = $this->api($this->doctorUser)->getJson('/api/v1/cid?q=aguda')->json('data.0.code');
        $this->actingAs($this->doctorUser)->postJson(route('clinical.cid.favorite', $j06->id))->assertOk()->assertJsonPath('favorite', true);
        $after = $this->api($this->doctorUser)->getJson('/api/v1/cid?q=aguda')->json('data');

        $this->assertSame('J06.9', $after[0]['code']);
        $this->assertTrue($after[0]['favorite']);
        $this->assertNotSame('J06.9', $before);
    }

    public function test_medications_global_base_is_read_only_and_clinic_items_are_isolated(): void
    {
        $this->context()->runAsSystem(fn () => (new ClinicalCatalogSeeder)->run($this->context()));
        $admin = $this->clinic['admin'];
        $other = $this->createClinic('Clínica Beta')['admin'];

        $this->actingAs($admin)->post(route('medications.store'), ['active_ingredient' => 'Fórmula manipulada exclusiva', 'control_type' => 'none'])->assertRedirect();

        $this->assertNotEmpty($this->api($admin)->getJson('/api/v1/medications?q=manipulada')->json('data'));
        $this->assertEmpty($this->api($other)->getJson('/api/v1/medications?q=manipulada')->json('data'));
        $this->assertNotEmpty($this->api($other)->getJson('/api/v1/medications?q=amoxicilina')->json('data'));
        $this->assertTrue($this->api($admin)->getJson('/api/v1/medications?q=clonazepam')->json('data.0.controlled'));

        $global = $this->context()->runAsSystem(fn () => Medication::query()->whereNull('company_id')->firstOrFail());
        $this->actingAs($admin)->put(route('medications.update', $global), ['active_ingredient' => 'Alterado', 'control_type' => 'none'])->assertSessionHas('error');
        $this->assertNotSame('Alterado', $this->context()->runAsSystem(fn () => $global->fresh()->active_ingredient));

        $mine = $this->context()->runFor($admin->company_id, fn () => Medication::query()->whereNotNull('company_id')->firstOrFail());
        $this->actingAs($other)->put(route('medications.update', $mine->id), ['active_ingredient' => 'X', 'control_type' => 'none'])->assertNotFound();
    }

    public function test_medication_csv_import_into_global_base(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'med');
        file_put_contents($path, "principio_ativo;nome_comercial;apresentacao;concentracao;fabricante;via;posologia;controle\n"
            ."Metronidazol;;comprimido;400 mg;;oral;1 comprimido 8/8h;antimicrobial\n"
            ."Diazepam;;comprimido;10 mg;;oral;;B1\n;;;;;;;\n");

        $stats = $this->context()->runAsSystem(fn () => app(MedicationService::class)->import($path));
        $this->assertSame(2, $stats['created']);
        $this->assertSame('B1', $this->context()->runAsSystem(fn () => Medication::query()->where('active_ingredient', 'Diazepam')->whereNull('company_id')->value('control_type')));
        unlink($path);
    }

    public function test_triage_is_immutable_and_validates_vitals(): void
    {
        $nurse = $this->userWithRole($this->company(), 'enfermagem');
        $client = $this->api($nurse);

        $client->postJson('/api/v1/triages', ['patient_id' => $this->pat->id, 'appointment_id' => $this->appointmentId, 'bp_systolic' => 120])
            ->assertUnprocessable()->assertJsonValidationErrors('bp_diastolic');
        $client->postJson('/api/v1/triages', ['patient_id' => $this->pat->id, 'appointment_id' => $this->appointmentId, 'spo2' => 97, 'weight_kg' => 80, 'height_cm' => 180])
            ->assertCreated();

        $triage = $this->context()->runFor($this->company()->id, fn () => Triage::query()->firstOrFail());
        $this->assertSame(24.7, $triage->bmi());

        $this->expectException(LogicException::class);
        $this->context()->runFor($this->company()->id, fn () => $triage->update(['spo2' => 80]));
    }

    public function test_reception_cannot_record_triage_or_allergies(): void
    {
        $reception = $this->userWithRole($this->company(), 'recepcao');

        $this->api($reception)->postJson('/api/v1/triages', ['patient_id' => $this->pat->id])->assertForbidden();
        $this->api($reception)->postJson("/api/v1/patients/{$this->pat->id}/allergies", ['substance' => 'Dipirona', 'severity' => 'severe'])->assertForbidden();
        $this->api($this->doctorUser)->postJson("/api/v1/patients/{$this->pat->id}/allergies", ['substance' => 'Dipirona', 'severity' => 'severe'])->assertCreated();
        $this->api($this->doctorUser)->postJson("/api/v1/patients/{$this->pat->id}/allergies", ['substance' => ' dipirona ', 'severity' => 'mild'])
            ->assertUnprocessable()->assertJsonPath('code', 'duplicate_allergy');
        $this->api($this->doctorUser)->getJson("/api/v1/patients/{$this->pat->id}/allergies")->assertJsonCount(1, 'data')->assertJsonPath('data.0.substance', 'Dipirona');
    }
}
