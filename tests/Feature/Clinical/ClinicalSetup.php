<?php

namespace Tests\Feature\Clinical;

use App\Modules\Clinical\Models\CidCode;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use Tests\Feature\Scheduling\SchedulingSetup;

/** Cenário clínico: médica vinculada a um usuário com perfil "medico" + agendamento das 08:00. */
trait ClinicalSetup
{
    use SchedulingSetup;

    protected User $doctorUser;

    protected Patient $pat;

    protected string $appointmentId;

    protected function setUpClinical(): void
    {
        $this->setUpScheduling();
        $this->doctorUser = $this->userWithRole($this->company(), 'medico');
        $this->context()->runFor($this->company()->id, fn () => $this->doctor->forceFill(['user_id' => $this->doctorUser->id])->save());
        $this->pat = $this->patient('Paciente Clínico');
        $this->appointmentId = $this->api($this->clinic['admin'])->postJson('/api/v1/appointments', $this->bookPayload($this->pat, '08:00'))->json('data.id');
    }

    protected function cid(string $code, string $description): CidCode
    {
        return $this->context()->runAsSystem(fn () => CidCode::query()->firstOrCreate(['code' => $code], ['description' => $description]));
    }

    protected function startEncounter(): array
    {
        $r = $this->api($this->doctorUser)->postJson('/api/v1/encounters', ['appointment_id' => $this->appointmentId])->assertCreated();

        return [$r->json('data.id'), $r->json('data.revision')];
    }

    protected function completeData(array $extra = []): array
    {
        return array_merge(['chief_complaint' => 'Dor de garganta há 3 dias', 'history' => 'Odinofagia, sem febre.', 'conduct' => 'Sintomáticos e retorno se piora.'], $extra);
    }

    protected function secondDoctorUser(): User
    {
        $user = $this->userWithRole($this->company(), 'medico');
        $this->context()->runFor($this->company()->id, function () use ($user) {
            $d = Doctor::create(['name' => 'Dr. Outro', 'crm' => '9999', 'crm_state' => 'SP', 'user_id' => $user->id]);
            $d->branches()->attach($this->branch()->id, ['company_id' => $this->company()->id]);
        });

        return $user;
    }
}
