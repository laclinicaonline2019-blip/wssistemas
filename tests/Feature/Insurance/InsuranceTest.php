<?php

namespace Tests\Feature\Insurance;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Finance\Models\FinancialTransaction;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Insurance\Models\Authorization;
use App\Modules\Insurance\Models\Batch;
use App\Modules\Insurance\Models\Guide;
use App\Modules\Insurance\Models\Insurer;
use App\Modules\Insurance\Models\PriceItem;
use App\Modules\Insurance\Models\PriceTable;
use App\Modules\Insurance\Models\Procedure;
use App\Modules\Insurance\Tiss\TissMessageBuilder;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Models\PatientInsurance;
use App\Modules\Payments\Models\PaymentSplit;
use App\Modules\Payments\Models\SplitRule;
use DOMDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\Scheduling\SchedulingSetup;
use Tests\TestCase;

class InsuranceTest extends TestCase
{
    use SchedulingSetup;

    private array $tokens = [];

    private Insurer $insurer;

    private Procedure $consulta;

    private Procedure $exam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScheduling();

        [$this->insurer, $this->consulta, $this->exam] = $this->tenant(function () {
            $insurer = Insurer::create(['name' => 'Saúde Teste', 'ans_registry' => '123456', 'provider_code' => 'PREST01', 'payment_term_days' => 30]);
            $consulta = Procedure::create(['code' => '10101012', 'name' => 'Consulta em consultório', 'kind' => 'consultation']);
            $exam = Procedure::create(['code' => '40304361', 'name' => 'Hemograma com contagem de plaquetas', 'kind' => 'exam']);
            $table = PriceTable::create(['insurer_id' => $insurer->id, 'name' => 'Tabela 2026', 'valid_from' => '2026-01-01']);
            PriceItem::create(['price_table_id' => $table->id, 'procedure_id' => $consulta->id, 'price_cents' => 12000]);
            PriceItem::create(['price_table_id' => $table->id, 'procedure_id' => $exam->id, 'price_cents' => 1500, 'requires_authorization' => true, 'copay_type' => 'percent', 'copay_value' => 3000]);
            $this->service->update(['procedure_id' => $consulta->id]);
            $this->branch()->update(['cnes' => '1234567']);

            return [$insurer, $consulta, $exam];
        });
    }

    private function as($user): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->tokens[$user->id] ??= $this->apiToken($user);
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->tokens[$user->id]);
    }

    private function tenant(\Closure $fn): mixed
    {
        return $this->context()->runFor($this->company()->id, $fn);
    }

    /** Paciente com carteirinha do convênio de teste. */
    private function insured(string $validUntil = '2027-12-31', ?string $insurerId = null): array
    {
        $patient = $this->patient('Beneficiário');
        $card = $this->tenant(fn () => PatientInsurance::create([
            'patient_id' => $patient->id, 'insurer_id' => $insurerId ?? $this->insurer->id, 'insurer_name' => 'Saúde Teste',
            'card_number' => '0001234500012', 'valid_until' => $validUntil, 'is_primary' => true,
        ]));

        return [$patient, $card];
    }

    /** Agenda por convênio e registra a chegada → guia criada. */
    private function attendedGuide(string $time = '08:00'): Guide
    {
        [$patient, $card] = $this->insured();
        $id = $this->as($this->clinic['admin'])->postJson('/api/v1/appointments', $this->bookPayload($patient, $time, ['payer_type' => 'insurance', 'patient_insurance_id' => $card->id]))
            ->assertCreated()->json('data.id');
        $this->as($this->clinic['admin'])->postJson("/api/v1/appointments/{$id}/arrive")->assertCreated();

        return $this->tenant(fn () => Guide::query()->where('appointment_id', $id)->firstOrFail());
    }

    public function test_consultation_flow_from_arrival_to_tiss_batch_payment_glosa_and_doctor_share(): void
    {
        $this->tenant(fn () => SplitRule::create(['doctor_id' => $this->doctor->id, 'payer_type' => 'insurance', 'insurer_id' => $this->insurer->id, 'type' => 'percent', 'value' => 6000]));
        $guide = $this->attendedGuide();

        // Guia aberta na chegada com o procedimento do tipo de atendimento e o valor da tabela do convênio.
        $this->assertSame(['consulta', 'draft', 12000, '0001234500012', '00000001', '225125'], [$guide->guide_type, $guide->status, $guide->total_cents, $guide->card_number, $guide->number, $guide->cbo_code]);
        $this->assertSame(0, DB::table('receivables')->where('payer_type', 'private')->count()); // paciente de convênio não paga no balcão

        $admin = $this->clinic['admin'];
        $this->actingAs($admin)->post(route('guides.ready', $guide))->assertSessionHas('success');
        $this->actingAs($admin)->post(route('batches.store'), ['insurer_id' => $this->insurer->id, 'branch_id' => $this->branch()->id, 'guide_type' => 'consulta', 'guide_ids' => [$guide->id]])
            ->assertRedirect();
        $batch = $this->tenant(fn () => Batch::query()->firstOrFail());
        $this->actingAs($admin)->post(route('batches.close', $batch))->assertSessionHas('success');

        $batch->refresh();
        $this->assertSame(['closed', 12000, true], [$batch->status, $batch->total_cents, $batch->xml_schema_valid]);
        $this->assertSame('billed', $guide->fresh()->status);

        // XML: válido no XSD oficial da ANS e com o hash MD5 do epílogo correto.
        $builder = app(TissMessageBuilder::class);
        $this->assertSame([], $builder->validate($batch->xmlFile()));
        $doc = new DOMDocument;
        $doc->loadXML($batch->xmlFile());
        $ns = TissMessageBuilder::NS;
        $this->assertSame('ENVIO_LOTE_GUIAS', $doc->getElementsByTagNameNS($ns, 'tipoTransacao')->item(0)->nodeValue);
        $this->assertSame('123456', $doc->getElementsByTagNameNS($ns, 'registroANS')->item(0)->nodeValue);
        $this->assertSame('0001234500012', $doc->getElementsByTagNameNS($ns, 'numeroCarteira')->item(0)->nodeValue);
        $this->assertSame('120.00', $doc->getElementsByTagNameNS($ns, 'valorProcedimento')->item(0)->nodeValue);
        $this->assertSame('1234567', $doc->getElementsByTagNameNS($ns, 'CNES')->item(0)->nodeValue);
        $hashNode = $doc->getElementsByTagNameNS($ns, 'hash')->item(0);
        $hashNode->parentNode->removeChild($hashNode);
        $this->assertSame($batch->xml_hash, md5(mb_convert_encoding($this->textValues($doc->documentElement), 'ISO-8859-1', 'UTF-8')));
        $this->assertSame($batch->xml_hash, DB::table('insurance_batches')->value('xml_hash'));
        $this->actingAs($admin)->get(route('batches.xml', $batch))->assertOk()->assertHeader('Content-Type', 'application/xml; charset=ISO-8859-1');

        // Conta a receber do convênio, com vencimento pelo prazo do convênio.
        $receivable = $this->tenant(fn () => Receivable::query()->findOrFail($batch->receivable_id));
        $this->assertSame(['insurance', 'insurance', 12000, '2026-11-04'], [$receivable->origin, $receivable->payer_type, $receivable->amount_cents, $receivable->due_date->toDateString()]);

        // XML e totais do lote fechado são imutáveis.
        $this->tenant(function () use ($batch) {
            $this->expectExceptionObject(new LogicException('Lote fechado: XML e totais são imutáveis.'));
            $batch->forceFill(['xml' => '<x/>'])->save();
        });
    }

    public function test_return_with_glosa_appeal_recovery_and_insurance_split(): void
    {
        $this->tenant(fn () => SplitRule::create(['doctor_id' => $this->doctor->id, 'payer_type' => 'insurance', 'type' => 'percent', 'value' => 6000]));
        $guide = $this->attendedGuide();
        $admin = $this->clinic['admin'];
        $this->actingAs($admin)->post(route('guides.ready', $guide));
        $this->actingAs($admin)->post(route('batches.store'), ['insurer_id' => $this->insurer->id, 'branch_id' => $this->branch()->id, 'guide_type' => 'consulta', 'guide_ids' => [$guide->id]]);
        $batch = $this->tenant(fn () => Batch::query()->firstOrFail());
        $this->actingAs($admin)->post(route('batches.close', $batch));

        // Glosa sem motivo é recusada.
        $this->actingAs($admin)->post(route('batches.return', $batch), ['method' => 'bank_transfer', 'paid_on' => self::MONDAY, 'guides' => [$guide->id => ['paid' => '100,00']]])
            ->assertSessionHas('error');
        // Valor acima da guia é recusado.
        $this->actingAs($admin)->post(route('batches.return', $batch), ['method' => 'bank_transfer', 'paid_on' => self::MONDAY, 'guides' => [$guide->id => ['paid' => '130,00']]])
            ->assertSessionHas('error');

        $this->actingAs($admin)->post(route('batches.return', $batch), ['method' => 'bank_transfer', 'paid_on' => self::MONDAY,
            'guides' => [$guide->id => ['paid' => '100,00', 'glosa_code' => '1705', 'glosa_reason' => 'Valor acima da tabela']]])->assertSessionHas('success');

        $guide->refresh();
        $this->assertSame(['partial', 10000, 2000, 'pending'], [$guide->status, $guide->paid_cents, $guide->glosa_cents, $guide->glosa_status]);
        $this->assertSame('partial', $batch->fresh()->status);
        $receivable = $this->tenant(fn () => Receivable::query()->findOrFail($batch->fresh()->receivable_id));
        $this->assertSame([10000, 'partial'], [$receivable->paid_cents, $receivable->status]);
        $txn = $this->tenant(fn () => FinancialTransaction::query()->where('receivable_id', $receivable->id)->firstOrFail());
        $this->assertSame(['bank_transfer', null], [$txn->method, $txn->cash_session_id]); // pagamento do convênio não passa pelo caixa
        $this->assertSame([[6000, 10000, 'internal', 'pending']], $this->tenant(fn () => PaymentSplit::query()->get()->map(fn ($s) => [$s->amount_cents, $s->base_cents, $s->mode, $s->status])->all()));

        // Pagamento de convênio não é estornado manualmente (guias e repasses dependem do retorno).
        $this->as($admin)->postJson("/api/v1/transactions/{$txn->id}/reverse", ['reason' => 'Estorno indevido de teste'])
            ->assertUnprocessable()->assertJsonPath('code', 'insurance_payment_not_reversible');

        // Concluir recurso exige recurso registrado.
        $this->actingAs($admin)->post(route('guides.glosa', $guide), ['action' => 'recover', 'recovered' => '15,00'])->assertSessionHas('error');
        $this->actingAs($admin)->post(route('guides.glosa', $guide), ['action' => 'appeal', 'appeal_text' => 'Valor conforme contrato vigente, anexo.'])->assertSessionHas('success');
        $this->assertSame('appealed', $guide->fresh()->glosa_status);

        $this->actingAs($admin)->post(route('guides.glosa', $guide), ['action' => 'recover', 'recovered' => '15,00', 'method' => 'bank_transfer', 'paid_on' => self::MONDAY])->assertSessionHas('success');
        $guide->refresh();
        $receivable->refresh();
        $this->assertSame(['partial', 11500, 500, 'recovered'], [$guide->status, $guide->paid_cents, $guide->glosa_cents, $guide->glosa_status]);
        // 15,00 recebidos + 5,00 baixados como glosa definitiva: conta do convênio quitada.
        $this->assertSame([11500, 500, 'paid'], [$receivable->paid_cents, $receivable->discount_cents, $receivable->status]);
        $this->assertSame(['paid', 11500, 500], [$batch->fresh()->status, $batch->fresh()->paid_cents, $batch->fresh()->glosa_cents]);
        $this->assertSame(6900, (int) $this->tenant(fn () => PaymentSplit::query()->sum('amount_cents'))); // 60% de 100 + 60% de 15
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'insurance.glosa_recovered')->count());
    }

    public function test_accepting_glosa_writes_off_balance(): void
    {
        $guide = $this->attendedGuide();
        $admin = $this->clinic['admin'];
        $this->actingAs($admin)->post(route('guides.ready', $guide));
        $this->actingAs($admin)->post(route('batches.store'), ['insurer_id' => $this->insurer->id, 'branch_id' => $this->branch()->id, 'guide_type' => 'consulta', 'guide_ids' => [$guide->id]]);
        $batch = $this->tenant(fn () => Batch::query()->firstOrFail());
        $this->actingAs($admin)->post(route('batches.close', $batch));
        $this->actingAs($admin)->post(route('batches.return', $batch), ['method' => 'pix', 'paid_on' => self::MONDAY, 'guides' => [$guide->id => ['paid' => '0', 'glosa_reason' => 'Carteira inválida']]]);
        $this->assertSame('denied', $guide->fresh()->status);

        $this->actingAs($admin)->post(route('guides.glosa', $guide), ['action' => 'accept'])->assertSessionHas('success');
        $receivable = $this->tenant(fn () => Receivable::query()->findOrFail($batch->fresh()->receivable_id));
        $this->assertSame([0, 12000, 'paid'], [$receivable->paid_cents, $receivable->discount_cents, $receivable->status]);
        $this->assertSame(['accepted', 'paid'], [$guide->fresh()->glosa_status, $batch->fresh()->status]);
        $this->assertSame(0, DB::table('financial_transactions')->count()); // nenhuma movimentação de dinheiro
    }

    public function test_booking_checks_card_validity_credentialing_and_active_insurer(): void
    {
        $admin = $this->clinic['admin'];
        [$p1, $expired] = $this->insured('2026-10-04'); // vence antes da consulta
        $this->as($admin)->postJson('/api/v1/appointments', $this->bookPayload($p1, '08:00', ['payer_type' => 'insurance', 'patient_insurance_id' => $expired->id]))
            ->assertUnprocessable()->assertJsonPath('code', 'insurance_expired');

        [$p2, $card] = $this->insured();
        $other = $this->tenant(fn () => Doctor::create(['name' => 'Outro Médico', 'crm' => '7777', 'crm_state' => 'SP']));
        $this->tenant(fn () => $this->insurer->doctors()->sync([$other->id]));
        $this->as($admin)->postJson('/api/v1/appointments', $this->bookPayload($p2, '08:00', ['payer_type' => 'insurance', 'patient_insurance_id' => $card->id]))
            ->assertUnprocessable()->assertJsonPath('code', 'doctor_not_credentialed');

        $this->tenant(fn () => $this->insurer->doctors()->sync([$this->doctor->id, $other->id]));
        $this->tenant(fn () => $this->insurer->update(['is_active' => false]));
        $this->as($admin)->postJson('/api/v1/appointments', $this->bookPayload($p2, '08:00', ['payer_type' => 'insurance', 'patient_insurance_id' => $card->id]))
            ->assertUnprocessable()->assertJsonPath('code', 'insurer_inactive');

        $this->tenant(fn () => $this->insurer->update(['is_active' => true]));
        $this->as($admin)->postJson('/api/v1/appointments', $this->bookPayload($p2, '08:00', ['payer_type' => 'insurance', 'patient_insurance_id' => $card->id]))->assertCreated();
    }

    public function test_sp_sadt_guide_with_authorization_copay_and_schema_validation(): void
    {
        $admin = $this->clinic['admin'];
        [$patient, $card] = $this->insured();
        $this->actingAs($admin)->post(route('guides.store'), ['patient_id' => $patient->id, 'patient_insurance_id' => $card->id, 'branch_id' => $this->branch()->id,
            'doctor_id' => $this->doctor->id, 'guide_type' => 'sp_sadt', 'attendance_date' => self::MONDAY])->assertRedirect();
        $guide = $this->tenant(fn () => Guide::query()->firstOrFail());

        // Futuro não pode; consulta tem item único.
        $this->actingAs($admin)->post(route('guides.store'), ['patient_id' => $patient->id, 'patient_insurance_id' => $card->id, 'branch_id' => $this->branch()->id,
            'doctor_id' => $this->doctor->id, 'guide_type' => 'consulta', 'attendance_date' => '2026-10-06'])->assertSessionHas('error');

        $this->actingAs($admin)->post(route('guides.items.store', $guide), ['procedure_id' => $this->exam->id, 'quantity' => 2, 'execution_date' => self::MONDAY])->assertSessionHas('success');
        $guide->refresh();
        $this->assertSame(3000, $guide->total_cents);
        // Coparticipação de 30% vira cobrança particular do paciente (atendimento misto).
        $copay = $this->tenant(fn () => Receivable::query()->where('insurance_guide_id', $guide->id)->firstOrFail());
        $this->assertSame([900, 'private', 'insurance_copay', $patient->id], [$copay->amount_cents, $copay->payer_type, $copay->origin, $copay->patient_id]);

        // Exige autorização: sem ela, não fica pronta.
        $this->actingAs($admin)->post(route('guides.ready', $guide))->assertSessionHas('error');
        $this->assertSame('draft', $guide->fresh()->status);

        $this->actingAs($admin)->post(route('authorizations.store'), ['patient_id' => $patient->id, 'patient_insurance_id' => $card->id, 'branch_id' => $this->branch()->id,
            'procedure_id' => $this->exam->id, 'quantity' => 2])->assertRedirect();
        $auth = $this->tenant(fn () => Authorization::query()->firstOrFail());
        $this->actingAs($admin)->post(route('authorizations.decide', $auth), ['decision' => 'authorized'])->assertSessionHas('error'); // sem senha
        $this->actingAs($admin)->post(route('authorizations.decide', $auth), ['decision' => 'authorized', 'password' => 'SENHA123', 'valid_until' => '2026-10-30', 'operator_guide_number' => 'OP998877'])->assertSessionHas('success');

        $this->actingAs($admin)->put(route('guides.update', $guide), ['authorization_id' => $auth->id, 'consultation_type' => '1', 'attendance_type' => '23',
            'accident_indicator' => '9', 'character' => '1', 'cbo_code' => '123456', 'clinical_indication' => 'Rotina'])->assertSessionHas('success');
        $this->actingAs($admin)->post(route('guides.ready', $guide))->assertSessionHas('success');

        $this->actingAs($admin)->post(route('batches.store'), ['insurer_id' => $this->insurer->id, 'branch_id' => $this->branch()->id, 'guide_type' => 'sp_sadt', 'guide_ids' => [$guide->id]]);
        $batch = $this->tenant(fn () => Batch::query()->firstOrFail());
        // Guia em lote não pode ser alterada.
        $this->actingAs($admin)->post(route('guides.items.store', $guide), ['procedure_id' => $this->exam->id, 'quantity' => 1, 'execution_date' => self::MONDAY])->assertSessionHas('error');

        // CBO inexistente na tabela TISS: o XSD oficial recusa e o lote NÃO fecha.
        $this->actingAs($admin)->post(route('batches.close', $batch))->assertSessionHas('error');
        $this->assertSame(['open', null], [$batch->fresh()->status, $batch->fresh()->xml]);
        $this->assertSame(0, DB::table('receivables')->where('origin', 'insurance')->count());

        // Corrige o CBO (retira do lote, ajusta, devolve) e fecha com XML SP/SADT válido.
        $this->actingAs($admin)->delete(route('batches.guides.destroy', [$batch, $guide]))->assertSessionHas('success');
        $this->actingAs($admin)->put(route('guides.update', $guide), ['authorization_id' => $auth->id, 'consultation_type' => '1', 'attendance_type' => '23',
            'accident_indicator' => '9', 'character' => '1', 'cbo_code' => '225120', 'clinical_indication' => 'Rotina']);
        $this->actingAs($admin)->post(route('guides.ready', $guide))->assertSessionHas('success');
        $this->actingAs($admin)->post(route('batches.store'), ['insurer_id' => $this->insurer->id, 'branch_id' => $this->branch()->id, 'guide_type' => 'sp_sadt', 'guide_ids' => [$guide->id]]);
        $batch2 = $this->tenant(fn () => Batch::query()->where('status', 'open')->where('guides_count', 1)->firstOrFail());
        $this->actingAs($admin)->post(route('batches.close', $batch2))->assertSessionHas('success');

        $xml = $batch2->fresh()->xmlFile();
        $this->assertStringContainsString('<ans:guiaSP-SADT>', $xml);
        $this->assertStringContainsString('<ans:senha>SENHA123</ans:senha>', $xml);
        $this->assertStringContainsString('<ans:quantidadeExecutada>2</ans:quantidadeExecutada>', $xml);
        $this->assertSame('used', $auth->fresh()->status);
        $this->assertSame([], app(TissMessageBuilder::class)->validate($xml));

        // Guia faturada é imutável também direto no model.
        $this->tenant(function () use ($guide) {
            $this->expectException(LogicException::class);
            Guide::query()->findOrFail($guide->id)->update(['card_number' => 'OUTRA']);
        });
    }

    public function test_price_rules_and_batch_consistency(): void
    {
        $admin = $this->clinic['admin'];
        // Tabela com vigência sobreposta é recusada.
        $this->actingAs($admin)->post(route('insurers.tables.store', $this->insurer), ['name' => 'Outra', 'valid_from' => '2026-06-01'])->assertSessionHas('error');

        [$patient, $card] = $this->insured();
        $noPrice = $this->tenant(fn () => Procedure::create(['code' => '99999999', 'name' => 'Sem preço', 'kind' => 'exam']));
        $this->actingAs($admin)->post(route('guides.store'), ['patient_id' => $patient->id, 'patient_insurance_id' => $card->id, 'branch_id' => $this->branch()->id,
            'doctor_id' => $this->doctor->id, 'guide_type' => 'consulta', 'attendance_date' => self::MONDAY]);
        $guide = $this->tenant(fn () => Guide::query()->firstOrFail());
        $this->actingAs($admin)->post(route('guides.items.store', $guide), ['procedure_id' => $this->exam->id, 'quantity' => 1, 'execution_date' => self::MONDAY])->assertSessionHas('error');
        $this->actingAs($admin)->post(route('guides.items.store', $guide), ['procedure_id' => $noPrice->id, 'quantity' => 1, 'execution_date' => self::MONDAY])->assertSessionHas('error');
        $this->actingAs($admin)->post(route('guides.items.store', $guide), ['procedure_id' => $this->consulta->id, 'quantity' => 1, 'execution_date' => self::MONDAY])->assertSessionHas('success');
        $this->actingAs($admin)->post(route('guides.items.store', $guide), ['procedure_id' => $this->consulta->id, 'quantity' => 1, 'execution_date' => self::MONDAY])->assertSessionHas('error');
        $this->actingAs($admin)->post(route('guides.ready', $guide))->assertSessionHas('success');

        // Lote só com guias do mesmo convênio/unidade/tipo.
        $other = $this->tenant(fn () => Insurer::create(['name' => 'Outro Convênio', 'ans_registry' => '654321']));
        $this->actingAs($admin)->post(route('batches.store'), ['insurer_id' => $other->id, 'branch_id' => $this->branch()->id, 'guide_type' => 'consulta', 'guide_ids' => [$guide->id]])
            ->assertSessionHas('error');
        $this->assertSame(0, DB::table('insurance_batches')->count());

        // Cancelar lote fechado devolve as guias e cancela a conta do convênio.
        $this->actingAs($admin)->post(route('batches.store'), ['insurer_id' => $this->insurer->id, 'branch_id' => $this->branch()->id, 'guide_type' => 'consulta', 'guide_ids' => [$guide->id]]);
        $batch = $this->tenant(fn () => Batch::query()->firstOrFail());
        $this->actingAs($admin)->post(route('batches.close', $batch))->assertSessionHas('success');
        $this->actingAs($admin)->post(route('batches.cancel', $batch), ['reason' => 'Enviado para operadora errada'])->assertSessionHas('success');
        $this->assertSame(['cancelled', 'ready', null], [$batch->fresh()->status, $guide->fresh()->status, $guide->fresh()->batch_id]);
        $this->assertSame('cancelled', DB::table('receivables')->where('origin', 'insurance')->value('status'));
        $this->assertNotNull($batch->fresh()->xml); // histórico preservado
    }

    public function test_patient_card_used_in_guide_is_kept_inactive_and_masked_on_anonymization(): void
    {
        $guide = $this->attendedGuide();
        $patient = $this->tenant(fn () => Patient::query()->findOrFail($guide->patient_id));
        $admin = $this->clinic['admin'];

        // Edição do cadastro sem a carteirinha: ela fica inativa (a guia continua apontando para ela).
        $this->actingAs($admin)->put(route('patients.update', $patient), ['name' => $patient->name, 'birth_date' => '1985-01-01', 'insurances' => []])->assertRedirect();
        $card = $this->tenant(fn () => PatientInsurance::query()->findOrFail($guide->patient_insurance_id));
        $this->assertFalse($card->is_active);
        $this->assertSame(0, $this->tenant(fn () => $patient->fresh()->insurances()->count()));

        $this->as($admin)->postJson("/api/v1/patients/{$patient->id}/anonymize", ['reason' => 'Pedido do titular (LGPD)', 'confirm' => true])->assertOk();
        $this->assertSame('***0012', $this->tenant(fn () => PatientInsurance::query()->findOrFail($card->id))->card_number);
        $this->assertSame('0001234500012', $guide->fresh()->card_number); // guia (obrigação legal de faturamento) preservada
    }

    public function test_permissions_isolation_pages_and_api(): void
    {
        $guide = $this->attendedGuide();
        $admin = $this->clinic['admin'];
        $reception = $this->userWithRole($this->company(), 'recepcao');
        $doctor = $this->userWithRole($this->company(), 'medico');
        $finance = $this->userWithRole($this->company(), 'financeiro');

        $this->actingAs($reception)->get(route('authorizations.index'))->assertOk();
        $this->actingAs($reception)->get(route('guides.index'))->assertForbidden();
        $this->actingAs($reception)->post(route('insurers.store'), ['name' => 'X'])->assertForbidden();
        $this->actingAs($doctor)->get(route('insurers.index'))->assertForbidden();
        $this->actingAs($finance)->get(route('batches.index'))->assertOk();
        $this->actingAs($finance)->get(route('guides.show', $guide))->assertOk();

        // Outra clínica não enxerga a guia nem o convênio.
        $other = $this->createClinic('Clínica Beta');
        $this->actingAs($other['admin'])->get(route('guides.show', $guide->id))->assertNotFound();
        $this->actingAs($other['admin'])->get(route('insurers.show', $this->insurer->id))->assertNotFound();

        // Telas.
        $table = $this->tenant(fn () => PriceTable::query()->firstOrFail());
        foreach ([route('insurers.index'), route('insurers.show', $this->insurer), route('insurers.tables.show', $table), route('procedures.index'),
            route('authorizations.index'), route('authorizations.index', ['pq' => 'Benef']), route('guides.index'), route('guides.create'), route('guides.show', $guide),
            route('guides.print', $guide), route('batches.index'), route('agenda.show', $guide->appointment_id), route('splits.index'), route('patients.show', $guide->patient_id),
            route('patients.edit', $guide->patient_id), route('doctors.schedule.index', $this->doctor)] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
        $this->actingAs($admin)->get(route('guides.print', $guide))->assertSee('GUIA DE CONSULTA')->assertSee('Assinatura do beneficiário');

        // Cadastro web do convênio + importação de procedimentos (CSV ISO-8859-1).
        $this->actingAs($admin)->post(route('insurers.store'), ['name' => 'Convênio Novo', 'ans_registry' => '12345', 'tiss_version' => '4.01.00', 'payment_term_days' => 30, 'max_guides_per_batch' => 100])
            ->assertSessionHasErrors('ans_registry');
        $this->actingAs($admin)->post(route('insurers.store'), ['name' => 'Convênio Novo', 'ans_registry' => '345678', 'cnpj' => '11.222.333/0001-81', 'tiss_version' => '4.01.00', 'payment_term_days' => 45, 'max_guides_per_batch' => 50])
            ->assertRedirect();
        $file = UploadedFile::fake()->createWithContent('tuss.csv', mb_convert_encoding("10101039;Consulta em pronto socorro\n40301630;Glicose - pesquisa e/ou dosagem;exam\nlinha inválida\n", 'ISO-8859-1', 'UTF-8'));
        $this->actingAs($admin)->post(route('procedures.import'), ['file' => $file, 'table_code' => '22'])->assertSessionHas('success');
        $this->assertSame('consultation', DB::table('procedures')->where('code', '10101039')->value('kind'));
        $this->assertSame('Glicose - pesquisa e/ou dosagem', DB::table('procedures')->where('code', '40301630')->value('name'));

        // API.
        $this->as($admin)->getJson('/api/v1/insurers')->assertOk()->assertJsonPath('data.0.ans_registry', '345678');
        $this->as($admin)->getJson('/api/v1/insurance/price?'.http_build_query(['insurer_id' => $this->insurer->id, 'procedure_id' => $this->exam->id, 'date' => self::MONDAY]))
            ->assertOk()->assertJsonPath('data.price_cents', 1500)->assertJsonPath('data.requires_authorization', true);
        $this->as($admin)->getJson("/api/v1/insurance/guides/{$guide->id}")->assertOk()->assertJsonPath('data.total_cents', 12000)->assertJsonPath('data.items.0.code', '10101012');
        $this->as($admin)->postJson("/api/v1/insurance/guides/{$guide->id}/ready")->assertOk()->assertJsonPath('data.status', 'ready');
        $this->as($reception)->getJson('/api/v1/insurance/guides')->assertForbidden();
    }

    private function textValues(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= $child->nodeType === XML_TEXT_NODE ? trim($child->nodeValue) : ($child instanceof \DOMElement ? $this->textValues($child) : '');
        }

        return $out;
    }
}
