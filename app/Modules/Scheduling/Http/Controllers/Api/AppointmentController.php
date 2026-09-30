<?php

namespace App\Modules\Scheduling\Http\Controllers\Api;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Queue\Http\Resources\TicketResource;
use App\Modules\Scheduling\Http\Requests\BookAppointmentRequest;
use App\Modules\Scheduling\Http\Resources\AppointmentResource;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\AppointmentService;
use App\Modules\Scheduling\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    private const WITH = ['branch', 'doctor', 'patient', 'service', 'room'];

    public function __construct(
        private readonly BookingService $booking,
        private readonly AppointmentService $appointments,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'doctor_id' => ['nullable', 'string', 'size:26'],
            'branch_id' => ['nullable', 'string', 'size:26'],
            'patient_id' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', Rule::in(array_keys(Appointment::STATUSES))],
        ]);

        return AppointmentResource::collection(self::query($filters, $this->context)->with(self::WITH)->paginate(100));
    }

    public static function query(array $filters, TenantContext $context): Builder
    {
        $tz = 'America/Sao_Paulo';

        return Appointment::query()->accessible($context->allowedBranchIds())
            ->when($filters['date'] ?? null, function ($q, $date) use ($filters, $tz) {
                $q->where('starts_at', '>=', CarbonImmutable::parse($date, $tz)->startOfDay()->utc())
                    ->where('starts_at', '<=', CarbonImmutable::parse($filters['date_to'] ?? $date, $tz)->endOfDay()->utc());
            })
            ->when($filters['doctor_id'] ?? null, fn ($q, $v) => $q->where('doctor_id', $v))
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['patient_id'] ?? null, fn ($q, $v) => $q->where('patient_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderBy('starts_at');
    }

    public function store(BookAppointmentRequest $request): AppointmentResource
    {
        $appointment = $this->booking->book($request->user(), $request->validated() + ['channel' => $request->input('channel', 'api')]);

        return new AppointmentResource($appointment->load(self::WITH));
    }

    public function show(Appointment $appointment): AppointmentResource
    {
        $this->ensureAccessible($appointment);

        return new AppointmentResource($appointment->load(self::WITH));
    }

    public function confirm(Request $request, Appointment $appointment): AppointmentResource
    {
        $this->ensureAccessible($appointment);

        return new AppointmentResource($this->appointments->confirm($request->user(), $appointment, $request->input('channel', 'api'))->load(self::WITH));
    }

    public function cancel(Request $request, Appointment $appointment): AppointmentResource
    {
        $this->ensureAccessible($appointment);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        return new AppointmentResource($this->appointments->cancel($request->user(), $appointment, $data['reason'])->load(self::WITH));
    }

    public function noShow(Request $request, Appointment $appointment): AppointmentResource
    {
        $this->ensureAccessible($appointment);

        return new AppointmentResource($this->appointments->noShow($request->user(), $appointment)->load(self::WITH));
    }

    public function arrive(Request $request, Appointment $appointment): TicketResource
    {
        $this->ensureAccessible($appointment);
        $data = $request->validate(['ticket_type' => ['nullable', Rule::in(array_keys(config('aivexa.queue_types')))]]);

        return new TicketResource($this->appointments->arrive($request->user(), $appointment, $data['ticket_type'] ?? null));
    }

    public function reschedule(Request $request, Appointment $appointment): AppointmentResource
    {
        $this->ensureAccessible($appointment);
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'doctor_id' => ['nullable', 'string', 'size:26'],
            'branch_id' => ['nullable', 'string', 'size:26'],
        ]);

        $appointment = $this->booking->reschedule($request->user(), $appointment, $data['starts_at'], $data['doctor_id'] ?? null, $data['branch_id'] ?? null);

        return new AppointmentResource($appointment->load(self::WITH));
    }

    private function ensureAccessible(Appointment $appointment): void
    {
        $allowed = $this->context->allowedBranchIds();
        abort_if($allowed !== null && ! in_array($appointment->branch_id, $allowed, true), 404);
    }
}
