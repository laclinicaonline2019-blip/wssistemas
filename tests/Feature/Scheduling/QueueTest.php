<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Doctors\Models\Specialty;
use App\Modules\Scheduling\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QueueTest extends TestCase
{
    use SchedulingSetup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScheduling();
    }

    private function room(string $name = 'Consultório', string $number = '03'): Room
    {
        return $this->context()->runFor($this->company()->id, fn () => Room::create(['branch_id' => $this->branch()->id, 'name' => $name, 'number' => $number]));
    }

    public function test_arrival_generates_ticket_with_suggested_type_and_daily_numbering(): void
    {
        $client = $this->api($this->clinic['admin']);
        $young = $client->postJson('/api/v1/appointments', $this->bookPayload($this->patient('Jovem'), '08:00'))->json('data.id');
        $elder = $client->postJson('/api/v1/appointments', $this->bookPayload($this->patient('Idosa', ['birth_date' => '1950-03-03']), '08:30'))->json('data.id');

        $client->postJson("/api/v1/appointments/{$young}/arrive")->assertCreated()->assertJsonPath('data.code', 'A001');
        $client->postJson("/api/v1/appointments/{$elder}/arrive")->assertCreated()->assertJsonPath('data.code', 'P001')->assertJsonPath('data.is_priority', true);
        $client->postJson('/api/v1/queue/tickets', ['type' => 'geral'], ['X-Branch-Id' => $this->branch()->id])->assertJsonPath('data.code', 'A002');

        $client->getJson("/api/v1/appointments/{$young}")->assertJsonPath('data.status', 'arrived');
        $client->postJson("/api/v1/appointments/{$young}/arrive")->assertJsonPath('code', 'invalid_transition');

        // Novo dia: numeração reinicia.
        $this->travelTo(CarbonImmutable::parse('2026-10-06 07:00', 'America/Sao_Paulo'));
        $client->postJson('/api/v1/queue/tickets', ['type' => 'geral'], ['X-Branch-Id' => $this->branch()->id])->assertJsonPath('data.code', 'A001');
    }

    public function test_call_next_prioritizes_and_full_ticket_lifecycle_updates_appointment(): void
    {
        $room = $this->room();
        $client = $this->api($this->clinic['admin'])->withHeader('X-Branch-Id', $this->branch()->id);
        $apptId = $client->postJson('/api/v1/appointments', $this->bookPayload($this->patient('Maria Silva Souza'), '08:00'))->json('data.id');
        $normal = $client->postJson("/api/v1/appointments/{$apptId}/arrive", ['ticket_type' => 'geral'])->json('data.id');
        $client->postJson('/api/v1/queue/tickets', ['type' => 'prioridade'])->assertCreated();

        $client->postJson('/api/v1/queue/call-next', ['room_id' => $room->id])->assertOk()->assertJsonPath('data.code', 'P001');
        $client->postJson('/api/v1/queue/call-next')->assertOk()->assertJsonPath('data.code', 'A001');
        $client->postJson('/api/v1/queue/call-next')->assertUnprocessable()->assertJsonPath('code', 'queue_empty');

        $client->postJson("/api/v1/queue/tickets/{$normal}/recall")->assertOk()->assertJsonPath('data.call_count', 2);
        $client->postJson("/api/v1/queue/tickets/{$normal}/start")->assertOk()->assertJsonPath('data.status', 'in_service');
        $client->getJson("/api/v1/appointments/{$apptId}")->assertJsonPath('data.status', 'in_service');
        $client->postJson("/api/v1/queue/tickets/{$normal}/finish")->assertOk()->assertJsonPath('data.status', 'done');
        $client->getJson("/api/v1/appointments/{$apptId}")->assertJsonPath('data.status', 'completed');
        $client->postJson("/api/v1/queue/tickets/{$normal}/skip")->assertJsonPath('code', 'invalid_ticket_status');
    }

    public function test_panel_shows_minimal_data_and_is_protected_by_token(): void
    {
        $room = $this->room('Sala', '3');
        $admin = $this->clinic['admin'];
        $this->actingAs($admin)->put(route('queue.panel.update'), ['branch_id' => $this->branch()->id, 'show_name' => 'short', 'repeat' => 2, 'volume' => 1, 'sound' => 1, 'voice' => 1])->assertSessionHas('success');
        $token = DB::table('branches')->where('id', $this->branch()->id)->value('settings');
        $token = json_decode($token, true)['panel']['token'];

        $client = $this->api($admin)->withHeader('X-Branch-Id', $this->branch()->id);
        $apptId = $client->postJson('/api/v1/appointments', $this->bookPayload($this->patient('x', ['name' => 'maria da silva souza']), '08:00'))->json('data.id');
        $ticket = $client->postJson("/api/v1/appointments/{$apptId}/arrive")->json('data.id');
        $client->postJson("/api/v1/queue/tickets/{$ticket}/call", ['room_id' => $room->id])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $state = $this->getJson("/painel/{$token}/estado")->assertOk();
        $state->assertJsonPath('current.code', 'A001')->assertJsonPath('current.room', 'Sala 3');
        $this->assertMatchesRegularExpression('/^Maria S\.$/', $state->json('current.name'));
        $this->assertStringNotContainsString('souza', strtolower(json_encode($state->json())));

        $this->get("/painel/{$token}")->assertOk()->assertSee('SENHA');
        $this->getJson('/painel/'.str_repeat('x', 40).'/estado')->assertNotFound();
        $this->getJson('/painel/curto/estado')->assertNotFound();

        // Rotação invalida o endereço antigo.
        $this->actingAs($admin)->put(route('queue.panel.update'), ['branch_id' => $this->branch()->id, 'show_name' => 'none', 'repeat' => 1, 'volume' => 1, 'rotate_token' => 1]);
        $this->app['auth']->forgetGuards();
        $this->getJson("/painel/{$token}/estado")->assertNotFound();

        // O token do painel nunca vai para a auditoria.
        $this->assertStringNotContainsString($token, DB::table('audit_logs')->get()->toJson());
    }

    public function test_queue_is_isolated_by_branch_and_company(): void
    {
        $client = $this->api($this->clinic['admin'])->withHeader('X-Branch-Id', $this->branch()->id);
        $ticket = $client->postJson('/api/v1/queue/tickets', ['type' => 'geral'])->json('data.id');

        ['admin' => $other] = $this->createClinic('Outra');
        $this->api($other)->postJson("/api/v1/queue/tickets/{$ticket}/call")->assertNotFound();

        $sul = $this->createBranch($this->company(), 'Sul');
        $recepSul = $this->api($this->userWithRole($this->company(), 'recepcao', $sul));
        $recepSul->postJson("/api/v1/queue/tickets/{$ticket}/call")->assertNotFound();
        $recepSul->getJson('/api/v1/queue')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_web_pages_render(): void
    {
        $admin = $this->clinic['admin'];
        $this->room();
        $apptId = $this->api($admin)->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), '08:00'))->json('data.id');
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->actingAs($admin);

        $this->get(route('agenda.index', ['date' => self::MONDAY]))->assertOk()->assertSee('Dra. Agenda')->assertSee('08:30');
        $this->get(route('agenda.index', ['date' => self::MONDAY, 'specialty_id' => $this->context()->runFor($this->company()->id, fn () => Specialty::query()->where('name', 'Cardiologia')->value('id'))]))->assertOk()->assertSee('Próximos horários');
        $this->get(route('agenda.create', ['doctor_id' => $this->doctor->id, 'branch_id' => $this->branch()->id, 'starts_at' => $this->at('09:00')]))->assertOk()->assertSee('Confirmar agendamento');
        $this->get(route('agenda.show', $apptId))->assertOk()->assertSee('Registrar chegada');
        $this->getJson(route('agenda.patient_lookup', ['q' => 'Paciente']))->assertOk()->assertJsonStructure(['data' => [['id', 'name', 'record_number', 'insurances']]]);
        $this->get(route('doctors.schedule.index', $this->doctor))->assertOk()->assertSee('Grade semanal');
        $this->get(route('rooms.index'))->assertOk();
        $this->get(route('agenda.holidays'))->assertOk();
        $this->get(route('queue.index'))->assertOk();
        $this->get(route('queue.panel'))->assertOk();
        $this->get('/')->assertOk()->assertSee('Consultas hoje');

        // Fluxo web: agendar → chegada → senha impressa
        $this->post(route('agenda.store'), $this->bookPayload($this->patient(), '09:00') + ['idempotency_key' => 'web-1'])->assertRedirect();
        $this->post(route('agenda.action', [$apptId, 'arrive']), ['ticket_type' => 'geral'])->assertSessionHas('print_ticket');
        $ticket = session('print_ticket');
        $this->get(route('queue.print', $ticket))->assertOk()->assertSee('A001');
        $this->post(route('agenda.action', [$apptId, 'cancel']), ['reason' => 'teste'])->assertSessionHas('success');
    }
}
