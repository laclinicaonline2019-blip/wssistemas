<?php

namespace Tests\Feature\Patients;

use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Models\PatientConsent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

class PatientTest extends TestCase
{
    private const CPF = '529.982.247-25';

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Maria José da Conceição',
            'birth_date' => '1980-05-10',
            'sex' => 'F',
            'cpf' => self::CPF,
            'whatsapp' => '(11) 98765-4321',
            'email' => 'maria@example.test',
            'zip_code' => '01310-100',
            'city' => 'São Paulo',
            'state' => 'SP',
        ], $overrides);
    }

    public function test_creates_patient_with_sequential_record_number_normalized_data_and_audit(): void
    {
        ['admin' => $admin] = $this->createClinic();
        $client = $this->api($admin);

        $first = $client->postJson('/api/v1/patients', $this->payload([
            'insurances' => [['insurer_name' => 'Plano X', 'card_number' => '123', 'is_primary' => true]],
        ]))->assertCreated();

        $first->assertJsonPath('data.record_number', 1)
            ->assertJsonPath('data.cpf', '52998224725')
            ->assertJsonPath('data.whatsapp', '11987654321')
            ->assertJsonPath('data.insurances.0.is_primary', true);

        $client->postJson('/api/v1/patients', $this->payload(['name' => 'João Silva', 'cpf' => null, 'whatsapp' => null, 'birth_date' => '1990-01-01']))
            ->assertCreated()->assertJsonPath('data.record_number', 2);

        $this->assertSame('maria jose da conceicao', DB::table('patients')->where('record_number', 1)->value('search_name'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'patient.created', 'user_id' => $admin->id]);
    }

    public function test_cpf_is_validated_and_unique_per_company_only(): void
    {
        ['admin' => $a] = $this->createClinic('A');
        ['admin' => $b] = $this->createClinic('B');

        $this->api($a)->postJson('/api/v1/patients', $this->payload(['cpf' => '111.111.111-11']))->assertJsonValidationErrors('cpf');
        $this->api($a)->postJson('/api/v1/patients', $this->payload())->assertCreated();
        $this->api($a)->postJson('/api/v1/patients', $this->payload(['name' => 'Outra', 'birth_date' => '1970-01-01', 'whatsapp' => null]))
            ->assertJsonValidationErrors('cpf');

        // Outra clínica pode ter o mesmo paciente (cadastros independentes) e com nº de prontuário próprio.
        $this->api($b)->postJson('/api/v1/patients', $this->payload())->assertCreated()->assertJsonPath('data.record_number', 1);
    }

    public function test_possible_duplicates_require_explicit_confirmation(): void
    {
        ['admin' => $admin] = $this->createClinic();
        $client = $this->api($admin);
        $client->postJson('/api/v1/patients', $this->payload(['cpf' => null]))->assertCreated();

        $client->postJson('/api/v1/patients', $this->payload(['cpf' => null, 'name' => 'MARIA JOSE DA CONCEICAO', 'whatsapp' => null]))
            ->assertStatus(409)->assertJsonPath('code', 'possible_duplicate')->assertJsonCount(1, 'candidates');

        $client->postJson('/api/v1/patients', $this->payload(['cpf' => null, 'whatsapp' => null, 'confirm_duplicate' => true]))->assertCreated();
    }

    public function test_minor_requires_legal_guardian(): void
    {
        ['admin' => $admin] = $this->createClinic();
        $minor = $this->payload(['cpf' => null, 'birth_date' => now()->subYears(5)->format('Y-m-d')]);

        $this->api($admin)->postJson('/api/v1/patients', $minor)->assertUnprocessable()->assertJsonPath('code', 'guardian_required');
        $this->api($admin)->postJson('/api/v1/patients', $minor + ['contacts' => [
            ['type' => 'guardian', 'name' => 'Ana Responsável', 'relationship' => 'mãe', 'phone' => '11999998888'],
        ]])->assertCreated()->assertJsonPath('data.contacts.0.type', 'guardian');
    }

    public function test_search_by_name_without_accents_cpf_phone_and_record_number(): void
    {
        ['admin' => $admin] = $this->createClinic();
        $client = $this->api($admin);
        $client->postJson('/api/v1/patients', $this->payload())->assertCreated();
        $client->postJson('/api/v1/patients', $this->payload(['name' => 'Pedro Alves', 'cpf' => null, 'whatsapp' => '11912345678', 'birth_date' => '2000-01-01']))->assertCreated();

        foreach (['conceicao', 'Conceição', '529.982.247-25', '98765-4321', '1'] as $term) {
            $client->getJson('/api/v1/patients?search='.urlencode($term))
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.record_number', 1);
        }

        // Listagem mascara o CPF; detalhe mostra completo e registra o acesso.
        $client->getJson('/api/v1/patients')->assertJsonPath('data.0.cpf', '***.982.247-**');
        $id = Patient::query()->withoutGlobalScopes()->where('record_number', 1)->value('id');
        $client->getJson("/api/v1/patients/{$id}")->assertJsonPath('data.cpf', '52998224725');
        $this->assertDatabaseHas('audit_logs', ['action' => 'patient.viewed', 'auditable_id' => $id]);
    }

    public function test_patients_are_isolated_between_companies(): void
    {
        ['admin' => $a] = $this->createClinic('A');
        ['admin' => $b] = $this->createClinic('B');
        $id = $this->api($a)->postJson('/api/v1/patients', $this->payload())->json('data.id');

        $client = $this->api($b);
        $client->getJson('/api/v1/patients')->assertJsonCount(0, 'data');
        $client->getJson("/api/v1/patients/{$id}")->assertNotFound();
        $client->patchJson("/api/v1/patients/{$id}", ['name' => 'Invadido'])->assertNotFound();
        $client->getJson("/api/v1/patients/{$id}/export")->assertNotFound();
        $client->postJson("/api/v1/patients/{$id}/anonymize", ['reason' => 'tentativa indevida', 'confirm' => true])->assertNotFound();
        $this->assertSame('Maria José da Conceição', DB::table('patients')->where('id', $id)->value('name'));
    }

    public function test_permissions_by_profile(): void
    {
        ['company' => $company, 'admin' => $admin, 'branch' => $branch] = $this->createClinic();
        $id = $this->api($admin)->postJson('/api/v1/patients', $this->payload())->json('data.id');

        $medico = $this->api($this->userWithRole($company, 'medico'));
        $medico->getJson("/api/v1/patients/{$id}")->assertOk();
        $medico->postJson('/api/v1/patients', $this->payload(['cpf' => null]))->assertForbidden();

        $financeiro = $this->api($this->userWithRole($company, 'financeiro'));
        $financeiro->getJson('/api/v1/patients')->assertForbidden();

        $recepcao = $this->api($this->userWithRole($company, 'recepcao', $branch));
        $recepcao->patchJson("/api/v1/patients/{$id}", ['phone' => '1133334444'])->assertOk();
        $recepcao->getJson("/api/v1/patients/{$id}/export")->assertForbidden();
        $recepcao->postJson("/api/v1/patients/{$id}/anonymize", ['reason' => 'pedido do titular', 'confirm' => true])->assertForbidden();
    }

    public function test_consents_keep_full_history_and_are_immutable(): void
    {
        ['admin' => $admin] = $this->createClinic();
        $client = $this->api($admin);
        $id = $client->postJson('/api/v1/patients', $this->payload())->json('data.id');

        $client->postJson("/api/v1/patients/{$id}/consents", ['purpose' => 'whatsapp_comunicacoes', 'granted' => true, 'channel' => 'presencial'])->assertCreated();
        $client->postJson("/api/v1/patients/{$id}/consents", ['purpose' => 'whatsapp_comunicacoes', 'granted' => false, 'channel' => 'whatsapp'])->assertCreated();
        $client->postJson("/api/v1/patients/{$id}/consents", ['purpose' => 'inexistente', 'granted' => true, 'channel' => 'presencial'])->assertJsonValidationErrors('purpose');

        $client->getJson("/api/v1/patients/{$id}")->assertJsonPath('data.consents.whatsapp_comunicacoes.granted', false);
        $this->assertSame(2, DB::table('patient_consents')->where('patient_id', $id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'patient.consent_revoked', 'auditable_id' => $id]);

        $this->expectException(LogicException::class);
        $this->context()->runAsSystem(fn () => PatientConsent::query()->first()->update(['notes' => 'adulterado']));
    }

    public function test_export_and_anonymization_lgpd(): void
    {
        ['admin' => $admin] = $this->createClinic();
        $client = $this->api($admin);
        $id = $client->postJson('/api/v1/patients', $this->payload([
            'contacts' => [['type' => 'emergency', 'name' => 'Contato X', 'phone' => '11911112222']],
        ]))->json('data.id');

        $client->getJson("/api/v1/patients/{$id}/export")->assertOk()
            ->assertJsonPath('data.patient.cpf', '52998224725')->assertJsonCount(1, 'data.contacts');
        $this->assertDatabaseHas('audit_logs', ['action' => 'patient.exported', 'auditable_id' => $id]);

        $client->postJson("/api/v1/patients/{$id}/anonymize", ['reason' => 'curto'])->assertUnprocessable();
        $client->postJson("/api/v1/patients/{$id}/anonymize", ['reason' => 'Solicitação do titular protocolo 123', 'confirm' => true])
            ->assertOk()->assertJsonPath('data.anonymized', true)->assertJsonPath('data.cpf', null);

        $row = DB::table('patients')->where('id', $id)->first();
        $this->assertSame('Paciente anonimizado #1', $row->name);
        $this->assertNull($row->whatsapp);
        $this->assertSame('1980-01-01', substr((string) $row->birth_date, 0, 10));
        $this->assertSame(0, DB::table('patient_contacts')->where('patient_id', $id)->count());

        // O evento de anonimização não copia dados pessoais para a trilha.
        $log = DB::table('audit_logs')->where('action', 'patient.anonymized')->first();
        $this->assertNull($log->old_values);
        $this->assertStringNotContainsString('52998224725', (string) $log->metadata);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'patient.updated')->where('auditable_id', $id)->count());

        $client->patchJson("/api/v1/patients/{$id}", ['name' => 'Reidentificar'])->assertUnprocessable()->assertJsonPath('code', 'patient_anonymized');
        $client->postJson("/api/v1/patients/{$id}/anonymize", ['reason' => 'Solicitação repetida 123', 'confirm' => true])->assertUnprocessable();
        $this->artisan('aivexa:audit:verify')->assertSuccessful();
    }

    public function test_patients_are_never_physically_deleted(): void
    {
        ['admin' => $admin, 'company' => $company] = $this->createClinic();
        $this->api($admin)->postJson('/api/v1/patients', $this->payload())->assertCreated();

        $this->expectException(LogicException::class);
        $this->context()->runFor($company->id, fn () => Patient::query()->first()->forceDelete());
    }

    public function test_web_pages_and_duplicate_confirmation_flow(): void
    {
        ['admin' => $admin] = $this->createClinic();
        $this->actingAs($admin);

        $this->get(route('patients.index'))->assertOk();
        $this->get(route('patients.create'))->assertOk()->assertSee('Responsáveis e contatos');

        $this->post(route('patients.store'), $this->payload(['contacts' => [['type' => 'guardian', 'name' => '', 'phone' => '']]]))
            ->assertRedirect();
        $patient = Patient::query()->withoutGlobalScopes()->firstOrFail();

        $this->get(route('patients.show', $patient))->assertOk()->assertSee('529.982.247-25')->assertSee('Consentimentos');
        $this->get(route('patients.edit', $patient))->assertOk();
        $this->get(route('search', ['q' => 'conceicao']))->assertOk()->assertSee('#1');

        // Nome e nascimento iguais → volta ao formulário pedindo confirmação.
        $this->from(route('patients.create'))->post(route('patients.store'), $this->payload(['cpf' => null, 'whatsapp' => null]))
            ->assertRedirect(route('patients.create'))->assertSessionHas('duplicates');
        $this->post(route('patients.store'), $this->payload(['cpf' => null, 'whatsapp' => null, 'confirm_duplicate' => '1']))->assertRedirect();
        $this->assertSame(2, DB::table('patients')->count());

        $this->get(route('patients.export', $patient))->assertOk()->assertHeader('content-type', 'application/json; charset=UTF-8');
    }

    public function test_cep_lookup_is_done_server_side_and_fails_gracefully(): void
    {
        ['admin' => $admin] = $this->createClinic();
        Http::fake([
            'viacep.com.br/ws/01310100/*' => Http::response(['logradouro' => 'Avenida Paulista', 'bairro' => 'Bela Vista', 'localidade' => 'São Paulo', 'uf' => 'SP']),
            'viacep.com.br/ws/99999999/*' => Http::response(['erro' => true]),
        ]);

        $this->actingAs($admin)->getJson('/cep/01310-100')->assertOk()->assertJsonPath('street', 'Avenida Paulista');
        $this->actingAs($admin)->getJson('/cep/99999999')->assertNotFound();
        $this->get('/cep/01310100')->assertOk(); // em cache
        auth()->logout();
        $this->getJson('/cep/01310100')->assertUnauthorized();
    }
}
