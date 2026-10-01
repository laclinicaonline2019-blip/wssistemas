<?php

namespace Tests\Feature\Documents;

use App\Modules\Documents\Models\MedicalDocument;
use App\Modules\Documents\Models\PatientFile;
use App\Modules\Platform\Models\SaasPlan;
use Database\Seeders\ClinicalCatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Clinical\ClinicalSetup;
use Tests\TestCase;

class DocumentWebTest extends TestCase
{
    use ClinicalSetup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClinical();
        $this->context()->runAsSystem(fn () => (new ClinicalCatalogSeeder)->run($this->context()));
    }

    public function test_doctor_issues_every_document_type_through_the_web(): void
    {
        [$encounterId] = $this->startEncounter();
        $this->actingAs($this->doctorUser);
        $base = ['patient_id' => $this->pat->id, 'encounter_id' => $encounterId];

        foreach (['prescription', 'certificate', 'exam_request', 'report'] as $type) {
            $this->get(route('documents.create', ['type' => $type] + $base))->assertOk();
        }

        $this->post(route('documents.store'), ['type' => 'prescription'] + $base + ['items' => [
            ['name' => 'Dipirona 500 mg', 'posology' => '6/6h se dor', 'quantity' => '1 caixa', 'control_type' => 'none'],
            ['name' => '', 'posology' => ''], // linha em branco do formulário é ignorada
        ]])->assertRedirect()->assertSessionHas('success');

        $this->post(route('documents.store'), ['type' => 'certificate', 'subtype' => 'leave', 'days' => 2, 'start_date' => now('America/Sao_Paulo')->toDateString()] + $base)->assertRedirect();
        $this->post(route('documents.store'), ['type' => 'exam_request', 'exams_text' => "Hemograma completo\n\nTSH\r\nTSH", 'indication' => 'Check-up'] + $base)->assertRedirect();
        $this->post(route('documents.store'), ['type' => 'report', 'subtype' => 'referral', 'recipient' => 'Ao cardiologista', 'body' => 'Encaminho para avaliação.'] + $base)->assertRedirect();

        $docs = $this->context()->runFor($this->company()->id, fn () => MedicalDocument::query()->orderBy('number')->get());
        $this->assertSame(['prescription', 'certificate', 'exam_request', 'report'], $docs->pluck('type')->all());
        $this->assertSame(['Hemograma completo', 'TSH'], $docs[2]->content['exams']);
        $this->assertSame([1, 2, 3, 4], $docs->pluck('number')->all());

        $this->get(route('documents.show', $docs[0]))->assertOk()->assertSee('Imprimir A4')->assertSee($docs[0]->formattedCode());
        $this->get(route('documents.index'))->assertOk()->assertSee('Encaminhamento');
        $this->get(route('encounters.edit', $encounterId))->assertOk()->assertSee('Atestado médico');
        $this->get(route('patients.show', $this->pat))->assertOk()->assertSee('Documentos emitidos')->assertSee('Solicitação de exames');

        $this->post(route('documents.cancel', $docs[1]), ['reason' => 'Emitido para o paciente errado'])->assertRedirect();
        $this->get(route('documents.show', $docs[1]))->assertSee('Emitido para o paciente errado')->assertDontSee('Imprimir A4');
    }

    public function test_reception_reprints_but_cannot_issue_and_menu_respects_permissions(): void
    {
        $this->actingAs($this->doctorUser)->post(route('documents.store'), ['type' => 'prescription', 'patient_id' => $this->pat->id, 'branch_id' => $this->branch()->id,
            'items' => [['name' => 'Paracetamol', 'posology' => '6/6h', 'quantity' => '1 cx']]]);
        $doc = $this->context()->runFor($this->company()->id, fn () => MedicalDocument::query()->firstOrFail());

        $reception = $this->userWithRole($this->company(), 'recepcao');
        $this->actingAs($reception)->get(route('documents.index'))->assertOk()->assertSee('Receita');
        $this->get(route('documents.print', $doc))->assertOk();
        $this->get(route('documents.create', ['type' => 'prescription', 'patient_id' => $this->pat->id]))->assertForbidden();
        $this->post(route('documents.cancel', $doc), ['reason' => 'Recepção tentando cancelar'])->assertForbidden();

        $financial = $this->userWithRole($this->company(), 'financeiro');
        $this->actingAs($financial)->get(route('documents.index'))->assertForbidden();
        $this->get(route('documents.show', $doc))->assertForbidden();
    }

    public function test_patient_files_are_private_audited_typed_and_limited_by_plan(): void
    {
        Storage::fake('local');
        $reception = $this->userWithRole($this->company(), 'recepcao');
        $pdf = UploadedFile::fake()->createWithContent('resultado.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");

        $this->actingAs($reception)->post(route('patient_files.store', $this->pat), ['file' => $pdf, 'category' => 'exam_result', 'title' => 'Hemograma'])
            ->assertRedirect()->assertSessionHas('success');
        $file = $this->context()->runFor($this->company()->id, fn () => PatientFile::query()->firstOrFail());
        Storage::disk('local')->assertExists($file->path);
        $this->assertStringStartsWith("companies/{$this->company()->id}/patients/{$this->pat->id}/", $file->path);
        $this->assertSame('application/pdf', $file->mime);

        // Arquivo disfarçado (texto com extensão .pdf) é recusado.
        $fake = UploadedFile::fake()->createWithContent('virus.pdf', '<?php echo 1;');
        $this->post(route('patient_files.store', $this->pat), ['file' => $fake, 'category' => 'other'])->assertSessionHas('error');

        // Recepção anexa mas não abre; médico abre (acesso auditado).
        $this->get(route('patient_files.download', $file))->assertForbidden();
        $this->actingAs($this->doctorUser)->get(route('patient_files.download', [$file, 'inline' => 1]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertDatabaseHas('audit_logs', ['action' => 'patient_file.downloaded', 'auditable_id' => $file->id]);

        // Arquivar não apaga.
        $this->actingAs($reception)->patch(route('patient_files.archive', $file))->assertRedirect();
        $this->assertSame('archived', $file->fresh()->status);
        Storage::disk('local')->assertExists($file->path);

        // Limite de armazenamento do plano.
        $plan = $this->context()->runAsSystem(fn () => SaasPlan::create(['code' => 'mini', 'name' => 'Mini', 'price_monthly_cents' => 1, 'price_yearly_cents' => 1, 'limits' => ['storage_mb' => 0]]));
        $this->context()->runAsSystem(fn () => $this->company()->forceFill(['saas_plan_id' => $plan->id])->save());
        $this->actingAs($reception)->post(route('patient_files.store', $this->pat), ['file' => UploadedFile::fake()->createWithContent('b.pdf', "%PDF-1.4\n%%EOF"), 'category' => 'other'])
            ->assertSessionHas('error');
        $this->assertSame(1, DB::table('patient_files')->count());

        // Outra clínica não acessa.
        $outsider = $this->createClinic('Clínica Beta')['admin'];
        $this->actingAs($outsider)->get(route('patient_files.download', $file))->assertNotFound();
    }
}
