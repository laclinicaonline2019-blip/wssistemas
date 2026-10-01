<?php

namespace Tests\Feature\Documents;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Clinical\Models\CidCode;
use App\Modules\Clinical\Models\Medication;
use App\Modules\Documents\Models\MedicalDocument;
use Database\Seeders\ClinicalCatalogSeeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\Clinical\ClinicalSetup;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use ClinicalSetup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClinical();
        $this->context()->runAsSystem(fn () => (new ClinicalCatalogSeeder)->run($this->context()));
    }

    private function med(string $ingredient): Medication
    {
        return $this->context()->runAsSystem(fn () => Medication::query()->where('active_ingredient', $ingredient)->firstOrFail());
    }

    private array $tokens = [];

    /** Cliente da API reaproveitando um token por usuário (evita o rate limit de login). */
    private function as($user): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->tokens[$user->id] ??= $this->apiToken($user);
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->tokens[$user->id]);
    }

    private function issue(string $type, array $payload, $user = null)
    {
        return $this->as($user ?? $this->doctorUser)->postJson('/api/v1/documents', ['type' => $type, 'patient_id' => $this->pat->id, 'branch_id' => $this->branch()->id] + $payload);
    }

    private function doc(string $id): MedicalDocument
    {
        return $this->context()->runFor($this->company()->id, fn () => MedicalDocument::query()->findOrFail($id));
    }

    public function test_prescription_is_split_according_to_portaria_344(): void
    {
        $items = [
            ['medication_id' => $this->med('Dipirona monoidratada')->id, 'posology' => '1 comprimido de 6/6h se dor', 'quantity' => '1 caixa'],
            ['medication_id' => $this->med('Amoxicilina')->id, 'posology' => '1 cápsula de 8/8h por 7 dias', 'quantity' => '21 (vinte e uma) cápsulas'],
            ['medication_id' => $this->med('Clonazepam')->id, 'posology' => '1/2 comprimido à noite', 'quantity' => '30 comprimidos', 'notification_number' => 'SP-1234567'],
            ['name' => 'Soro caseiro', 'posology' => 'Tomar à vontade'],
        ];

        $r = $this->issue('prescription', ['items' => $items, 'notes' => 'Retornar em 7 dias.'])->assertCreated();
        $docs = collect($r->json('data'))->keyBy('type');

        $this->assertSame(['prescription', 'special_prescription', 'notification_record'], $docs->keys()->all());
        $this->assertCount(1, $docs->pluck('group_id')->unique());
        $this->assertSame(now('America/Sao_Paulo')->addDays(10)->toDateString(), $docs['special_prescription']['valid_until']);

        $simple = $this->doc($docs['prescription']['id']);
        $this->assertSame(['Dipirona monoidratada 500 mg (comprimido)', 'Soro caseiro'], array_column($simple->content['items'], 'name'));
        $this->assertSame('Dra. Agenda', $simple->content['doctor']['name']);
        $this->assertSame('SP-1234567', $this->doc($docs['notification_record']['id'])->content['items'][0]['notification_number']);
    }

    public function test_controlled_items_require_quantity_and_notification_number(): void
    {
        $this->issue('prescription', ['items' => [['medication_id' => $this->med('Clonazepam')->id, 'posology' => 'à noite', 'quantity' => '30']]])
            ->assertUnprocessable()->assertJsonPath('code', 'notification_required');
        $this->issue('prescription', ['items' => [['medication_id' => $this->med('Sertralina')->id, 'posology' => '1 ao dia']]])
            ->assertUnprocessable()->assertJsonPath('code', 'quantity_required');
        $this->issue('prescription', ['items' => [['name' => 'Morfina', 'control_type' => 'A1', 'posology' => 'se dor']]])
            ->assertUnprocessable()->assertJsonPath('code', 'notification_required');
        $this->assertSame(0, DB::table('medical_documents')->count());
    }

    public function test_issued_document_is_immutable_and_tampering_is_detected(): void
    {
        $id = $this->issue('exam_request', ['exams' => ['Hemograma completo', 'TSH']])->assertCreated()->json('data.0.id');
        $doc = $this->doc($id);

        $this->context()->runFor($this->company()->id, function () use ($doc) {
            try {
                $doc->update(['content' => ['exams' => ['Outro']]]);
                $this->fail('Conteúdo do documento não pode mudar.');
            } catch (LogicException) {
            }
            try {
                $doc->delete();
                $this->fail('Documento não pode ser excluído.');
            } catch (LogicException) {
            }
        });

        $doc = $this->doc($id);
        $this->as($this->doctorUser)->getJson("/api/v1/documents/{$id}")->assertJsonPath('data.intact', true);
        DB::table('medical_documents')->where('id', $id)->update(['content' => json_encode(array_merge($doc->content, ['exams' => ['Exame adulterado']]))]);
        $this->as($this->doctorUser)->getJson("/api/v1/documents/{$id}")->assertJsonPath('data.intact', false);

        $this->get(route('documents.validate', $doc->formattedCode()))->assertOk()->assertSee('não confere com o selo de integridade');
    }

    public function test_certificate_text_cid_authorization_and_date_rules(): void
    {
        $r = $this->issue('certificate', ['subtype' => 'leave', 'days' => 3, 'start_date' => now('America/Sao_Paulo')->toDateString()])->assertCreated();
        $text = $this->doc($r->json('data.0.id'))->content['text'];
        $this->assertStringContainsString('3 (três) dias de afastamento', $text);
        $this->assertStringContainsString($this->pat->name, $text);
        $this->assertStringNotContainsString('CID', $text);

        $cid = $this->context()->runAsSystem(fn () => CidCode::query()->where('code', 'J06.9')->value('id'));
        $this->issue('certificate', ['subtype' => 'leave', 'days' => 1, 'cid_code_id' => $cid])->assertUnprocessable()->assertJsonPath('code', 'cid_not_authorized');
        $withCid = $this->issue('certificate', ['subtype' => 'leave', 'days' => 1, 'cid_code_id' => $cid, 'cid_authorized' => true])->assertCreated();
        $this->assertStringContainsString('CID-10: J06.9', $this->doc($withCid->json('data.0.id'))->content['text']);

        $this->issue('certificate', ['subtype' => 'leave', 'days' => 2, 'start_date' => now()->subDays(10)->toDateString()])
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_start_date');
        $this->issue('certificate', ['subtype' => 'attendance', 'start_time' => '10:00', 'end_time' => '09:00'])->assertUnprocessable();
        $att = $this->issue('certificate', ['subtype' => 'attendance', 'start_time' => '08:00', 'end_time' => '09:30'])->assertCreated();
        $this->assertStringContainsString('das 08:00 às 09:30', $this->doc($att->json('data.0.id'))->content['text']);
    }

    public function test_permissions_by_role_and_author_only_cancellation(): void
    {
        $id = $this->issue('prescription', ['items' => [['name' => 'Paracetamol 750 mg', 'posology' => '6/6h', 'quantity' => '1 cx']]])->json('data.0.id');
        $examId = $this->issue('exam_request', ['exams' => ['Hemograma']])->json('data.0.id');

        $reception = $this->userWithRole($this->company(), 'recepcao');
        $this->issue('prescription', ['items' => [['name' => 'X', 'posology' => 'Y']]], $reception)->assertForbidden();
        $this->as($reception)->getJson("/api/v1/documents/{$id}")->assertOk();          // reimpressão de receita
        $this->as($reception)->getJson("/api/v1/documents/{$examId}")->assertForbidden(); // sem acesso a exames
        $this->as($reception)->postJson("/api/v1/documents/{$id}/cancel", ['reason' => 'Cancelamento indevido'])->assertForbidden();

        $this->issue('prescription', ['items' => [['name' => 'X', 'posology' => 'Y']]], $this->clinic['admin'])->assertForbidden()->assertJsonPath('code', 'not_a_doctor');

        $other = $this->secondDoctorUser();
        $this->as($other)->postJson("/api/v1/documents/{$id}/cancel", ['reason' => 'Tentativa de outro médico'])->assertForbidden()->assertJsonPath('code', 'not_author');

        $this->as($this->doctorUser)->postJson("/api/v1/documents/{$id}/cancel", ['reason' => 'curto'])->assertUnprocessable();
        $this->as($this->doctorUser)->postJson("/api/v1/documents/{$id}/cancel", ['reason' => 'Posologia digitada errada'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->as($this->doctorUser)->postJson("/api/v1/documents/{$id}/cancel", ['reason' => 'Posologia digitada errada'])->assertStatus(409);
        $this->as($this->doctorUser)->get("/api/v1/documents/{$id}/pdf")->assertStatus(409);

        $this->get(route('documents.validate', $this->doc($id)->formattedCode()))->assertSee('DOCUMENTO CANCELADO');
    }

    public function test_printing_counts_copies_supports_formats_and_pdf(): void
    {
        $group = $this->issue('prescription', ['items' => [
            ['name' => 'Ibuprofeno 600 mg', 'posology' => '8/8h por 5 dias', 'quantity' => '15 comp.'],
            ['medication_id' => $this->med('Azitromicina')->id, 'posology' => '1 ao dia por 3 dias', 'quantity' => '3 (três) comprimidos'],
        ]])->json('data');
        [$simple, $special] = [$this->doc($group[0]['id']), $this->doc($group[1]['id'])];
        $this->actingAs($this->doctorUser);

        $this->get(route('documents.print', [$simple, 'preview' => 1]))->assertOk()->assertSee('Ibuprofeno 600 mg');
        $this->assertSame(0, $simple->fresh()->print_count);

        $this->get(route('documents.print', [$simple, 'format' => 'a4']))->assertOk()->assertSee('Receituário')->assertSee($simple->formattedCode());
        $this->get(route('documents.print', [$simple, 'format' => 'thermal']))->assertOk()->assertSee('Reimpressão (via nº 2)');
        $this->get(route('documents.print', [$special, 'format' => 'a5']))->assertOk()
            ->assertSee('1ª via — retenção da farmácia')->assertSee('2ª via — orientação ao paciente')->assertSee('Identificação do comprador');
        $this->get(route('documents.print', [$special, 'format' => 'thermal']))->assertRedirect()->assertSessionHas('error');
        $this->get(route('documents.print_group', [$simple->group_id, 'format' => 'a4']))->assertOk()->assertSee('Azitromicina');

        $pdf = $this->get(route('documents.pdf', $special))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->assertSame(3, $simple->fresh()->print_count); // A4 + térmica + grupo (pré-visualização não conta)
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'document.printed')->where('auditable_id', $special->id)->where('metadata->format', 'pdf-a4')->count());
        $this->assertStringNotContainsString('Ibuprofeno', DB::table('audit_logs')->get()->toJson());
    }

    public function test_public_validation_shows_minimum_data(): void
    {
        $id = $this->issue('prescription', ['items' => [['name' => 'Loratadina 10 mg', 'posology' => '1 ao dia', 'quantity' => '10 comp.']]])->json('data.0.id');
        $doc = $this->doc($id);

        $page = $this->get('/validar/'.strtolower($doc->formattedCode()))->assertOk()
            ->assertSee('Documento autêntico e válido')->assertSee('Loratadina 10 mg')->assertSee('Dra. Agenda');
        $page->assertDontSee($this->pat->name);
        $this->get('/validar/AAAA-BBBB-CCCC')->assertOk()->assertSee('Documento não encontrado');
        $this->get('/validar/consulta?code='.$doc->verification_code)->assertRedirect(route('documents.validate', $doc->verification_code));
    }

    public function test_documents_are_isolated_between_clinics_and_tied_to_own_encounter(): void
    {
        $id = $this->issue('exam_request', ['exams' => ['TSH']])->json('data.0.id');
        $outsider = $this->createClinic('Clínica Beta')['admin'];
        $this->as($outsider)->getJson("/api/v1/documents/{$id}")->assertNotFound();

        [$encounterId] = $this->startEncounter();
        $other = $this->secondDoctorUser();
        $this->issue('exam_request', ['exams' => ['TSH'], 'encounter_id' => $encounterId], $other)->assertForbidden()->assertJsonPath('code', 'invalid_encounter');
        $this->issue('exam_request', ['exams' => ['TSH'], 'encounter_id' => $encounterId])->assertCreated()->assertJsonPath('data.0.encounter_id', $encounterId);
    }
}
