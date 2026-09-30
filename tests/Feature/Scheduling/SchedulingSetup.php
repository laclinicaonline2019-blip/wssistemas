<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Organization\Models\Branch;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Company;
use App\Modules\Scheduling\Models\DoctorService;
use App\Modules\Scheduling\Models\ScheduleTemplate;
use Carbon\CarbonImmutable;

/**
 * Cenário padrão: segunda-feira 05/10/2026, 06:00 (São Paulo).
 * Grade da médica: segundas 08:00–10:00, horários de 30 min (4 vagas), 1 encaixe.
 */
trait SchedulingSetup
{
    protected array $clinic;

    protected Doctor $doctor;

    protected DoctorService $service;

    protected ScheduleTemplate $template;

    protected const MONDAY = '2026-10-05';

    protected function setUpScheduling(array $templateOverrides = []): void
    {
        $this->travelTo(CarbonImmutable::parse(self::MONDAY.' 06:00', 'America/Sao_Paulo'));
        $this->clinic = $this->createClinic();

        [$this->doctor, $this->service, $this->template] = $this->context()->runFor($this->clinic['company']->id, function () use ($templateOverrides) {
            $doctor = Doctor::create(['name' => 'Dra. Agenda', 'crm' => '5000', 'crm_state' => 'SP']);
            $doctor->branches()->attach($this->clinic['branch']->id, ['company_id' => $this->clinic['company']->id]);
            $cardio = Specialty::query()->where('name', 'Cardiologia')->value('id');
            $doctor->specialties()->attach($cardio, ['company_id' => $this->clinic['company']->id]);
            $service = DoctorService::create(['doctor_id' => $doctor->id, 'name' => 'Consulta', 'price_cents' => 25000, 'accepts_insurance' => true]);
            $template = ScheduleTemplate::create(array_merge([
                'doctor_id' => $doctor->id, 'branch_id' => $this->clinic['branch']->id, 'weekday' => 1,
                'start_time' => '08:00', 'end_time' => '10:00', 'slot_minutes' => 30, 'max_overbooks' => 1,
            ], $templateOverrides));

            return [$doctor, $service, $template];
        });
    }

    protected function patient(string $name = 'Paciente Teste', array $attrs = []): Patient
    {
        static $n = 0;
        $n++;

        return $this->context()->runFor($this->clinic['company']->id, function () use ($name, $attrs, $n) {
            $p = new Patient(array_merge(['name' => $name.' '.$n, 'birth_date' => '1985-01-01'], $attrs));
            $p->record_number = 1000 + $n;
            $p->save();

            return $p;
        });
    }

    /** Horário local (São Paulo) da segunda de teste → ISO-8601. */
    protected function at(string $time, string $date = self::MONDAY): string
    {
        return CarbonImmutable::parse("{$date} {$time}", 'America/Sao_Paulo')->toIso8601String();
    }

    protected function bookPayload(Patient $patient, string $time, array $extra = []): array
    {
        return array_merge([
            'doctor_id' => $this->doctor->id,
            'branch_id' => $this->clinic['branch']->id,
            'patient_id' => $patient->id,
            'service_id' => $this->service->id,
            'starts_at' => $this->at($time),
        ], $extra);
    }

    protected function company(): Company
    {
        return $this->clinic['company'];
    }

    protected function branch(): Branch
    {
        return $this->clinic['branch'];
    }
}
