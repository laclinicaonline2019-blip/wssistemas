<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\Holiday;
use App\Modules\Scheduling\Models\ScheduleBlock;
use App\Modules\Scheduling\Models\ScheduleTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use SchedulingSetup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScheduling();
    }

    public function test_availability_reflects_the_real_schedule_and_booking_occupies_the_slot(): void
    {
        $client = $this->api($this->clinic['admin']);
        $query = ['doctor_id' => $this->doctor->id, 'branch_id' => $this->branch()->id, 'date_from' => self::MONDAY];

        $slots = $client->getJson('/api/v1/availability/slots?'.http_build_query($query))->assertOk()->json('data.0.slots');
        $this->assertSame(['08:00', '08:30', '09:00', '09:30'], array_column($slots, 'local_time'));
        $this->assertSame(['free'], array_values(array_unique(array_column($slots, 'status'))));

        $response = $client->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), '08:00'))->assertCreated();
        $response->assertJsonPath('data.local_time', '08:00')->assertJsonPath('data.price_cents', 25000)->assertJsonPath('data.status', 'scheduled');
        $this->assertMatchesRegularExpression('/^AG26\d{6}$/', $response->json('data.protocol'));

        $slots = $client->getJson('/api/v1/availability/slots?'.http_build_query($query))->json('data.0.slots');
        $this->assertSame('booked', $slots[0]['status']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'appointment.created']);
    }

    public function test_the_same_slot_cannot_be_booked_twice(): void
    {
        $client = $this->api($this->clinic['admin']);
        $client->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), '08:00'))->assertCreated();

        $client->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), '08:00'))
            ->assertStatus(409)->assertJsonPath('code', 'slot_booked');
        $this->assertSame(1, Appointment::query()->withoutGlobalScopes()->count());
    }

    public function test_database_blocks_duplicate_slot_even_bypassing_the_service(): void
    {
        $client = $this->api($this->clinic['admin']);
        $id = $client->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), '08:00'))->json('data.id');
        $row = (array) DB::table('appointments')->where('id', $id)->first();

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('appointments')->insert(array_merge(
            array_diff_key($row, array_flip(['holds_slot'])),
            ['id' => (string) str()->ulid(), 'protocol' => 'X1', 'patient_id' => $this->patient()->id, 'idempotency_key' => null],
        ));
    }

    public function test_service_duration_blocks_overlapping_slots(): void
    {
        $long = $this->context()->runFor($this->company()->id, fn () => $this->doctor->services()->create(['name' => 'Longa', 'duration_minutes' => 60, 'price_cents' => 1]));
        $client = $this->api($this->clinic['admin']);

        $client->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), '08:00', ['service_id' => $long->id]))->assertCreated();
        $client->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), '08:30'))->assertStatus(409);
        $client->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), '09:30', ['service_id' => $long->id]))
            ->assertUnprocessable()->assertJsonPath('code', 'slot_blocked'); // passaria do fim do período
    }

    public function test_period_limit_blocks_new_bookings_and_suggests_next_slot(): void
    {
        $this->template->update(['max_patients' => 2]);
        $client = $this->api($this->clinic['admin']);

        $client->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), '08:00'))->assertCreated();
        $client->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), '08:30'))->assertCreated();
        $client->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), '09:00'))
            ->assertUnprocessable()->assertJsonPath('code', 'slot_full');

        // Próximo horário disponível: segunda seguinte.
        $next = $client->getJson('/api/v1/availability/next?'.http_build_query(['branch_id' => $this->branch()->id, 'doctor_id' => $this->doctor->id, 'limit' => 1]))
            ->assertOk()->json('data.0');
        $this->assertSame('2026-10-12', $next['local_date']);
        $this->assertSame('08:00', $next['local_time']);
    }

    public function test_daily_limit_of_the_doctor(): void
    {
        $this->doctor->update(['daily_limit' => 1]);
        $client = $this->api($this->clinic['admin']);

        $client->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), '08:00'))->assertCreated();
        $client->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), '09:00'))->assertJsonPath('code', 'slot_full');
    }

    public function test_overbooks_require_permission_and_respect_limit(): void
    {
        $this->template->update(['max_patients' => 1]);
        $recepcao = $this->userWithRole($this->company(), 'recepcao', $this->branch());
        $this->api($this->clinic['admin'])->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), '08:00'))->assertCreated();

        // Sem permissão de encaixe
        $roleId = $this->api($this->clinic['admin'])->postJson('/api/v1/roles', ['name' => 'Agendador', 'permissions' => ['agenda.visualizar', 'agenda.criar']])->json('data.id');
        $agendador = $this->userWithRole($this->company(), 'enfermagem', $this->branch());
        $this->api($this->clinic['admin'])->putJson("/api/v1/users/{$agendador->id}/roles", ['roles' => [['role_id' => $roleId, 'branch_id' => $this->branch()->id]]])->assertOk();
        $this->api($agendador)->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), '08:30', ['is_overbook' => true]))
            ->assertForbidden()->assertJsonPath('code', 'overbook_forbidden');

        $client = $this->api($recepcao);
        $client->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), '08:30'))->assertJsonPath('code', 'slot_full');
        $client->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), '08:30', ['is_overbook' => true]))
            ->assertCreated()->assertJsonPath('data.is_overbook', true);
        $client->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), '09:00', ['is_overbook' => true]))
            ->assertUnprocessable()->assertJsonPath('code', 'overbook_limit');
    }

    public function test_holidays_blocks_past_and_off_grid_times_are_rejected(): void
    {
        $client = $this->api($this->clinic['admin']);
        $p = $this->patient();

        $client->postJson('/api/v1/appointments', $this->bookPayload($p, '08:15'))->assertJsonPath('code', 'off_grid');
        $client->postJson('/api/v1/appointments', $this->bookPayload($p, '11:00'))->assertJsonPath('code', 'no_schedule');

        $this->travelTo(CarbonImmutable::parse(self::MONDAY.' 08:40', 'America/Sao_Paulo'));
        $client->postJson('/api/v1/appointments', $this->bookPayload($p, '08:30'))->assertJsonPath('code', 'past_time');
        $this->travelTo(CarbonImmutable::parse(self::MONDAY.' 06:00', 'America/Sao_Paulo'));

        $this->context()->runFor($this->company()->id, fn () => ScheduleBlock::create([
            'doctor_id' => $this->doctor->id, 'starts_at' => CarbonImmutable::parse($this->at('09:00'))->utc(),
            'ends_at' => CarbonImmutable::parse($this->at('10:00'))->utc(), 'reason' => 'Congresso',
        ]));
        $client->postJson('/api/v1/appointments', $this->bookPayload($p, '09:00'))->assertJsonPath('code', 'slot_blocked');

        $this->context()->runFor($this->company()->id, fn () => Holiday::create(['date' => '2026-10-12', 'name' => 'Nossa Senhora Aparecida']));
        $client->postJson('/api/v1/appointments', $this->bookPayload($p, '08:00', ['starts_at' => $this->at('08:00', '2026-10-12')]))
            ->assertJsonPath('code', 'slot_blocked')->assertJsonFragment(['message' => 'Horário indisponível: Feriado: Nossa Senhora Aparecida']);
    }

    public function test_patient_and_doctor_consistency_rules(): void
    {
        $client = $this->api($this->clinic['admin']);
        $p = $this->patient();
        $client->postJson('/api/v1/appointments', $this->bookPayload($p, '08:00'))->assertCreated();

        // Mesmo paciente, mesmo horário, outro médico
        $other = $this->context()->runFor($this->company()->id, function () {
            $d = Doctor::create(['name' => 'Outro', 'crm' => '6000', 'crm_state' => 'SP']);
            $d->branches()->attach($this->branch()->id, ['company_id' => $this->company()->id]);
            ScheduleTemplate::create(['doctor_id' => $d->id, 'branch_id' => $this->branch()->id, 'weekday' => 1, 'start_time' => '08:00', 'end_time' => '09:00', 'slot_minutes' => 30]);

            return $d;
        });
        $client->postJson('/api/v1/appointments', ['doctor_id' => $other->id] + $this->bookPayload($p, '08:00', ['service_id' => null]))
            ->assertJsonPath('code', 'patient_conflict');

        // Paciente inativo
        $inactive = $this->patient('Inativo', ['status' => 'inactive']);
        $client->postJson('/api/v1/appointments', $this->bookPayload($inactive, '09:00'))->assertJsonPath('code', 'invalid_patient');

        // Médico não atende na unidade
        $sul = $this->createBranch($this->company(), 'Sul');
        $client->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), '09:00', ['branch_id' => $sul->id]))
            ->assertJsonPath('code', 'doctor_not_in_branch');
    }

    public function test_insurance_rules(): void
    {
        $client = $this->api($this->clinic['admin']);
        $p = $this->patient();
        [$valid, $expired] = $this->context()->runFor($this->company()->id, fn () => [
            $p->insurances()->create(['insurer_name' => 'Plano', 'card_number' => '1', 'valid_until' => '2027-01-01']),
            $p->insurances()->create(['insurer_name' => 'Velho', 'card_number' => '2', 'valid_until' => '2020-01-01']),
        ]);

        $client->postJson('/api/v1/appointments', $this->bookPayload($p, '08:00', ['payer_type' => 'insurance', 'patient_insurance_id' => $expired->id]))
            ->assertJsonPath('code', 'insurance_expired');
        $client->postJson('/api/v1/appointments', $this->bookPayload($p, '08:00', ['payer_type' => 'insurance', 'patient_insurance_id' => $valid->id]))
            ->assertCreated()->assertJsonPath('data.payer_type', 'insurance')->assertJsonPath('data.price_cents', 0);

        $this->service->update(['accepts_insurance' => false]);
        $client->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), '09:00', ['payer_type' => 'insurance', 'patient_insurance_id' => $valid->id]))
            ->assertJsonPath('code', 'insurance_not_accepted');
    }

    public function test_cancel_frees_the_slot_and_reschedule_keeps_protocol(): void
    {
        $client = $this->api($this->clinic['admin']);
        $a = $client->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), '08:00'))->json('data');

        $client->postJson("/api/v1/appointments/{$a['id']}/cancel", ['reason' => 'Paciente pediu'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $client->postJson("/api/v1/appointments/{$a['id']}/confirm")->assertUnprocessable()->assertJsonPath('code', 'invalid_transition');

        $b = $client->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), '08:00'))->assertCreated()->json('data');
        $c = $client->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), '09:00'))->json('data');

        $client->postJson("/api/v1/appointments/{$c['id']}/reschedule", ['starts_at' => $this->at('08:00')])->assertStatus(409);
        $client->postJson("/api/v1/appointments/{$c['id']}/reschedule", ['starts_at' => $this->at('09:30')])
            ->assertOk()->assertJsonPath('data.local_time', '09:30')->assertJsonPath('data.protocol', $c['protocol']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'appointment.rescheduled', 'auditable_id' => $c['id']]);
        $this->assertNotSame($a['protocol'], $b['protocol']);
    }

    public function test_idempotency_key_prevents_duplicate_booking_on_retry(): void
    {
        $client = $this->api($this->clinic['admin']);
        $payload = $this->bookPayload($this->patient(), '08:00', ['idempotency_key' => 'req-123']);

        $first = $client->postJson('/api/v1/appointments', $payload)->assertCreated()->json('data.id');
        $second = $client->postJson('/api/v1/appointments', $payload)->assertOk()->json('data.id'); // repetição: 200, mesmo registro

        $this->assertSame($first, $second);
        $this->assertSame(1, Appointment::query()->withoutGlobalScopes()->count());
    }

    public function test_templates_cannot_overlap_for_the_same_doctor_even_in_other_branches(): void
    {
        $sul = $this->createBranch($this->company(), 'Sul');
        $this->context()->runFor($this->company()->id, fn () => $this->doctor->branches()->attach($sul->id, ['company_id' => $this->company()->id]));
        $client = $this->api($this->clinic['admin']);

        $client->postJson("/api/v1/doctors/{$this->doctor->id}/schedule-templates", [
            'branch_id' => $sul->id, 'weekday' => 1, 'start_time' => '09:30', 'end_time' => '12:00', 'slot_minutes' => 30,
        ])->assertUnprocessable()->assertJsonPath('code', 'template_overlap');

        $client->postJson("/api/v1/doctors/{$this->doctor->id}/schedule-templates", [
            'branch_id' => $sul->id, 'weekday' => 1, 'start_time' => '14:00', 'end_time' => '18:00', 'slot_minutes' => 20, 'max_patients' => 10,
        ])->assertCreated();
    }

    public function test_block_reports_affected_appointments(): void
    {
        $client = $this->api($this->clinic['admin']);
        $client->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), '08:00'))->assertCreated();

        $client->postJson('/api/v1/schedule-blocks', [
            'doctor_id' => $this->doctor->id, 'starts_at' => self::MONDAY.' 07:00', 'ends_at' => self::MONDAY.' 12:00',
            'type' => 'vacation', 'reason' => 'Férias',
        ])->assertCreated()->assertJsonCount(1, 'affected_appointments');
    }

    public function test_appointments_are_isolated_between_companies_and_branches(): void
    {
        $id = $this->api($this->clinic['admin'])->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), '08:00'))->json('data.id');

        ['admin' => $otherAdmin, 'branch' => $otherBranch] = $this->createClinic('Outra');
        $other = $this->api($otherAdmin);
        $other->getJson("/api/v1/appointments/{$id}")->assertNotFound();
        $other->postJson("/api/v1/appointments/{$id}/cancel", ['reason' => 'invasão'])->assertNotFound();
        $other->postJson('/api/v1/appointments', array_merge($this->bookPayload($this->patient(), '09:00'), ['branch_id' => $otherBranch->id]))
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_doctor');
        $other->getJson('/api/v1/appointments?date='.self::MONDAY)->assertJsonCount(0, 'data');

        // Recepção restrita à filial Sul não vê nem agenda na matriz
        $sul = $this->createBranch($this->company(), 'Sul');
        $recepSul = $this->api($this->userWithRole($this->company(), 'recepcao', $sul));
        $recepSul->getJson("/api/v1/appointments/{$id}")->assertNotFound();
        $recepSul->withHeader('X-Branch-Id', $sul->id)->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), '09:00'))
            ->assertJsonPath('code', 'invalid_branch');
    }
}
