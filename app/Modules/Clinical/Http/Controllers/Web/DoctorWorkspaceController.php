<?php

namespace App\Modules\Clinical\Http\Controllers\Web;

use App\Core\Support\BusinessRuleViolation;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Clinical\Models\Encounter;
use App\Modules\Clinical\Services\EncounterService;
use App\Modules\Patients\Models\Patient;
use App\Modules\Queue\Models\QueueTicket;
use App\Modules\Queue\Services\QueueService;
use App\Modules\Scheduling\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Área do médico: pacientes do dia, chamar, iniciar e retomar atendimentos. */
class DoctorWorkspaceController extends Controller
{
    public function __construct(
        private readonly EncounterService $encounters,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): View
    {
        $doctor = $this->encounters->doctorFor($request->user());
        $tz = 'America/Sao_Paulo';
        $date = CarbonImmutable::parse($request->query('date', now($tz)->toDateString()), $tz);

        $appointments = $doctor ? Appointment::query()
            ->with(['patient:id,name,social_name,birth_date,record_number', 'service:id,name', 'branch:id,name', 'ticket', 'room'])
            ->where('doctor_id', $doctor->id)->accessible($this->context->allowedBranchIds())
            ->whereBetween('starts_at', [$date->startOfDay()->utc(), $date->endOfDay()->utc()])
            ->whereNotIn('status', ['cancelled'])
            ->orderByRaw("CASE status WHEN 'in_service' THEN 0 WHEN 'arrived' THEN 1 WHEN 'confirmed' THEN 2 WHEN 'scheduled' THEN 2 ELSE 3 END")
            ->orderBy('starts_at')->get() : collect();

        $encounters = $doctor ? Encounter::query()->where('doctor_id', $doctor->id)
            ->whereIn('appointment_id', $appointments->pluck('id'))->get()->keyBy('appointment_id') : collect();

        $drafts = $doctor ? Encounter::query()->with('patient:id,name,social_name,record_number')
            ->where('doctor_id', $doctor->id)->where('status', 'draft')->orderByDesc('started_at')->get() : collect();

        return view('clinical.workspace', compact('doctor', 'date', 'appointments', 'encounters', 'drafts'));
    }

    public function start(Request $request, Appointment $appointment): RedirectResponse
    {
        $encounter = $this->encounters->startFromAppointment($request->user(), $appointment);

        return redirect()->route('encounters.edit', $encounter);
    }

    public function walkIn(Request $request, Patient $patient): RedirectResponse
    {
        // Unidade: informada → unidade de trabalho selecionada → unidade de cadastro do paciente.
        $branchId = $request->validate(['branch_id' => ['nullable', 'string', 'size:26']])['branch_id']
            ?? $this->context->branchId() ?? $patient->home_branch_id;

        if (! $branchId) {
            throw new BusinessRuleViolation('Selecione a unidade de trabalho no topo da tela.', 'branch_required');
        }

        $encounter = $this->encounters->startWalkIn($request->user(), $patient, $branchId);

        return redirect()->route('encounters.edit', $encounter);
    }

    /** O médico chama o próprio paciente para o consultório (painel da TV). */
    public function call(Request $request, Appointment $appointment, QueueService $queue): RedirectResponse
    {
        $doctor = $this->encounters->doctorFor($request->user());
        abort_unless($doctor && $appointment->doctor_id === $doctor->id, 403);

        $ticket = QueueTicket::query()->where('appointment_id', $appointment->id)->whereIn('status', ['waiting', 'called'])->latest('arrived_at')->first()
            ?? throw new BusinessRuleViolation('Paciente ainda não registrou chegada na recepção.', 'not_arrived');

        $queue->call($request->user(), $ticket->branch_id, $ticket, $appointment->room_id);

        return back()->with('success', "Chamando {$ticket->code} para o consultório.");
    }
}
