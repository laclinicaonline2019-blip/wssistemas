<?php

namespace App\Modules\Scheduling\Http\Controllers\Api;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Organization\Models\Branch;
use App\Modules\Scheduling\Models\DoctorService;
use App\Modules\Scheduling\Services\AvailabilityService;
use App\Modules\Scheduling\Services\Slot;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Disponibilidade real da agenda (usada pela recepção, integrações e, na Fase 12, pela IA).
 */
class AvailabilityController extends Controller
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly TenantContext $context,
    ) {}

    /** Horários de um médico em uma unidade num período (máx. 31 dias). */
    public function slots(Request $request): JsonResponse
    {
        $data = $request->validate([
            'doctor_id' => ['required', 'string', 'size:26'],
            'branch_id' => ['required', 'string', 'size:26'],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'service_id' => ['nullable', 'string', 'size:26'],
            'only_free' => ['sometimes', 'boolean'],
        ]);

        $doctor = Doctor::query()->findOrFail($data['doctor_id']);
        $branch = Branch::query()->accessible($this->context->allowedBranchIds())->findOrFail($data['branch_id']);
        $duration = isset($data['service_id']) ? DoctorService::query()->where('doctor_id', $doctor->id)->findOrFail($data['service_id'])->duration_minutes : null;

        $from = CarbonImmutable::parse($data['date_from'], $branch->timezone);
        $to = CarbonImmutable::parse($data['date_to'] ?? $data['date_from'], $branch->timezone);
        abort_if($from->diffInDays($to) > 31, 422, 'Período máximo: 31 dias.');

        $days = [];
        for ($d = $from; $d <= $to; $d = $d->addDay()) {
            foreach ($this->availability->day($doctor, $branch, $d, $duration) as $period) {
                $slots = collect($period['slots'])
                    ->when($request->boolean('only_free'), fn ($c) => $c->filter(fn (Slot $s) => $s->isFree()))
                    ->map(fn (Slot $s) => $s->toArray($branch->timezone))->values();

                $days[] = [
                    'date' => $d->format('Y-m-d'),
                    'period' => $period['template']->label(),
                    'capacity' => $period['capacity'],
                    'booked' => $period['booked'],
                    'overbooks_used' => $period['overbooks'],
                    'overbooks_allowed' => $period['template']->max_overbooks,
                    'full' => $period['full'],
                    'slots' => $slots,
                ];
            }
        }

        return response()->json(['data' => $days, 'timezone' => $branch->timezone]);
    }

    /** Próximos horários livres por médico ou especialidade. */
    public function next(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'string', 'size:26'],
            'doctor_id' => ['nullable', 'required_without:specialty_id', 'string', 'size:26'],
            'specialty_id' => ['nullable', 'string', 'size:26'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $branch = Branch::query()->accessible($this->context->allowedBranchIds())->findOrFail($data['branch_id']);
        $doctor = isset($data['doctor_id']) ? Doctor::query()->findOrFail($data['doctor_id']) : null;

        $slots = $this->availability->next($branch, $doctor, $data['specialty_id'] ?? null, (int) ($data['limit'] ?? 5));
        $names = Doctor::query()->whereIn('id', collect($slots)->pluck('doctorId'))->get()->keyBy('id');

        return response()->json(['data' => collect($slots)->map(fn (Slot $s) => $s->toArray($branch->timezone) + [
            'doctor_name' => $names[$s->doctorId]?->displayName(),
        ])->values()]);
    }
}
