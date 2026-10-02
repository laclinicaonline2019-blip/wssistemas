<?php

namespace App\Modules\Scheduling\Http\Controllers\Web;

use App\Core\Support\Format;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Insurance\Models\Guide;
use App\Modules\Organization\Models\Branch;
use App\Modules\Patients\Models\Patient;
use App\Modules\Queue\Services\QueueService;
use App\Modules\Scheduling\Http\Requests\BookAppointmentRequest;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\AppointmentService;
use App\Modules\Scheduling\Services\AvailabilityService;
use App\Modules\Scheduling\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AgendaWebController extends Controller
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly BookingService $booking,
        private readonly AppointmentService $appointments,
        private readonly TenantContext $context,
    ) {}

    /** Agenda do dia por unidade (um quadro por médico). */
    public function index(Request $request): View
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'doctor_id' => ['nullable', 'string', 'size:26'], 'branch_id' => ['nullable', 'string', 'size:26']]);
        $branch = $this->resolveBranch($request->query('branch_id'));
        $date = CarbonImmutable::parse($request->query('date', CarbonImmutable::now($branch->timezone)->toDateString()), $branch->timezone);

        $doctors = Doctor::query()->active()->with('specialties:id,name')
            ->whereHas('branches', fn ($q) => $q->where('branches.id', $branch->id))
            ->when($request->query('doctor_id'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('name')->get();

        $appointments = Appointment::query()->with(['patient:id,name,social_name,record_number', 'service:id,name'])
            ->where('branch_id', $branch->id)
            ->whereBetween('starts_at', [$date->startOfDay()->utc(), $date->endOfDay()->utc()])
            ->orderBy('starts_at')->get()->groupBy('doctor_id');

        $boards = $doctors->map(fn (Doctor $d) => [
            'doctor' => $d,
            'periods' => $this->availability->day($d, $branch, $date),
            'appointments' => $appointments[$d->id] ?? collect(),
        ])->filter(fn ($b) => $b['periods'] !== [] || $b['appointments']->isNotEmpty())->values();

        return view('agenda.index', [
            'branch' => $branch,
            'date' => $date,
            'boards' => $boards,
            'branches' => Branch::query()->active()->accessible($this->context->allowedBranchIds())->orderByDesc('is_headquarters')->orderBy('name')->get(['id', 'name']),
            'allDoctors' => Doctor::query()->active()->whereHas('branches', fn ($q) => $q->where('branches.id', $branch->id))->orderBy('name')->get(['id', 'name', 'social_name']),
            'specialties' => Specialty::query()->active()->orderBy('name')->get(['id', 'name']),
            'next' => $request->filled('specialty_id') || $request->filled('next_doctor_id')
                ? $this->availability->next($branch, $request->filled('next_doctor_id') ? Doctor::query()->find($request->query('next_doctor_id')) : null, $request->query('specialty_id'), 8)
                : null,
            'doctorNames' => Doctor::query()->get(['id', 'name', 'social_name'])->mapWithKeys(fn (Doctor $d) => [$d->id => $d->displayName()]),
        ]);
    }

    public function create(Request $request): View
    {
        $data = $request->validate([
            'doctor_id' => ['required', 'string', 'size:26'],
            'branch_id' => ['required', 'string', 'size:26'],
            'starts_at' => ['required', 'date'],
            'overbook' => ['sometimes', 'boolean'],
            'patient_id' => ['nullable', 'string', 'size:26'],
        ]);

        $branch = Branch::query()->accessible($this->context->allowedBranchIds())->findOrFail($data['branch_id']);
        $doctor = Doctor::query()->with('services')->findOrFail($data['doctor_id']);

        return view('agenda.create', [
            'branch' => $branch,
            'doctor' => $doctor,
            'start' => CarbonImmutable::parse($data['starts_at'])->utc(),
            'overbook' => $request->boolean('overbook'),
            'services' => $doctor->services->where('is_active', true),
            'patient' => isset($data['patient_id']) ? Patient::query()->with('insurances')->find($data['patient_id']) : null,
        ]);
    }

    public function store(BookAppointmentRequest $request): RedirectResponse
    {
        $appointment = $this->booking->book($request->user(), $request->validated() + ['channel' => $request->input('channel', 'reception')]);

        return redirect()->route('agenda.show', $appointment)->with('success', "Agendamento confirmado — protocolo {$appointment->protocol}.");
    }

    public function show(Appointment $appointment): View
    {
        $this->ensureAccessible($appointment);
        $appointment->load(['branch', 'doctor', 'patient.insurances', 'service', 'room', 'insurance', 'creator:id,name', 'ticket']);

        $history = AuditLog::query()->with('user:id,name')
            ->where('auditable_type', 'appointment')->where('auditable_id', $appointment->id)
            ->orderByDesc('id')->limit(20)->get();

        return view('agenda.show', [
            'appointment' => $appointment,
            'history' => $history,
            'receivable' => Receivable::query()->where('appointment_id', $appointment->id)->first(),
            'guide' => $appointment->payer_type === 'insurance' ? Guide::query()->where('appointment_id', $appointment->id)->first() : null,
            'ticketTypes' => app(QueueService::class)->types(),
            'suggestedType' => app(QueueService::class)->suggestType($appointment),
        ]);
    }

    public function action(Request $request, Appointment $appointment, string $action): RedirectResponse
    {
        $this->ensureAccessible($appointment);
        $user = $request->user();

        $permission = match ($action) {
            'confirm', 'no-show', 'reschedule' => 'agenda.editar',
            'cancel' => 'agenda.cancelar',
            'arrive' => 'fila.gerenciar',
            default => abort(404),
        };
        abort_unless($user->hasPermission($permission, $appointment->branch_id), 403);

        switch ($action) {
            case 'confirm':
                $this->appointments->confirm($user, $appointment);
                $message = 'Presença confirmada.';
                break;
            case 'cancel':
                $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];
                $this->appointments->cancel($user, $appointment, $reason);
                $message = 'Agendamento cancelado. O horário foi liberado.';
                break;
            case 'no-show':
                $this->appointments->noShow($user, $appointment);
                $message = 'Falta registrada.';
                break;
            case 'arrive':
                $message = $this->arrive($request, $appointment);
                break;
            default:
                $message = $this->reschedule($request, $appointment);
        }

        return back()->with('success', $message);
    }

    /** Busca de pacientes para o formulário de agendamento (JSON). */
    public function patientLookup(Request $request): JsonResponse
    {
        $q = (string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q'];

        $patients = Patient::query()->with('insurances')->where('status', 'active')->whereNull('anonymized_at')
            ->search($q)->orderBy('search_name')->limit(10)->get();

        return response()->json(['data' => $patients->map(fn (Patient $p) => [
            'id' => $p->id,
            'name' => $p->displayName(),
            'record_number' => $p->record_number,
            'birth_date' => $p->birth_date?->format('d/m/Y'),
            'cpf' => Format::cpfMasked($p->cpf),
            'insurances' => $p->insurances->map(fn ($i) => ['id' => $i->id, 'label' => trim($i->insurer_name.' '.$i->plan_name).' · '.$i->card_number, 'expired' => $i->isExpired()])->values(),
        ])]);
    }

    private function arrive(Request $request, Appointment $appointment): string
    {
        $data = $request->validate(['ticket_type' => ['nullable', Rule::in(array_keys(config('aivexa.queue_types')))]]);
        $ticket = $this->appointments->arrive($request->user(), $appointment, $data['ticket_type'] ?? null);
        session()->flash('print_ticket', $ticket->id);

        return "Chegada registrada — senha {$ticket->code}.";
    }

    private function reschedule(Request $request, Appointment $appointment): string
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'time' => ['required', 'date_format:H:i']]);
        $tz = $appointment->branch->timezone;
        $this->booking->reschedule($request->user(), $appointment, CarbonImmutable::parse("{$data['date']} {$data['time']}", $tz));

        return 'Agendamento remarcado.';
    }

    private function resolveBranch(?string $branchId): Branch
    {
        $query = Branch::query()->active()->accessible($this->context->allowedBranchIds());

        return ($branchId ? (clone $query)->find($branchId) : null)
            ?? ($this->context->branchId() ? (clone $query)->find($this->context->branchId()) : null)
            ?? $query->orderByDesc('is_headquarters')->orderBy('name')->firstOrFail();
    }

    private function ensureAccessible(Appointment $appointment): void
    {
        $allowed = $this->context->allowedBranchIds();
        abort_if($allowed !== null && ! in_array($appointment->branch_id, $allowed, true), 404);
    }
}
