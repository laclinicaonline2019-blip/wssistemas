<?php

namespace App\Modules\Scheduling\Http\Controllers\Api;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Organization\Models\Branch;
use App\Modules\Scheduling\Http\Requests\DoctorServiceRequest;
use App\Modules\Scheduling\Http\Requests\HolidayRequest;
use App\Modules\Scheduling\Http\Requests\RoomRequest;
use App\Modules\Scheduling\Http\Requests\ScheduleBlockRequest;
use App\Modules\Scheduling\Http\Requests\ScheduleTemplateRequest;
use App\Modules\Scheduling\Models\DoctorService;
use App\Modules\Scheduling\Models\Holiday;
use App\Modules\Scheduling\Models\Room;
use App\Modules\Scheduling\Models\ScheduleBlock;
use App\Modules\Scheduling\Models\ScheduleTemplate;
use App\Modules\Scheduling\Services\ScheduleConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Configuração da agenda via API (grades, serviços, bloqueios, feriados, salas). */
class ScheduleConfigController extends Controller
{
    public function __construct(
        private readonly ScheduleConfigService $config,
        private readonly TenantContext $context,
    ) {}

    public function templates(Doctor $doctor): JsonResponse
    {
        return response()->json(['data' => $doctor->scheduleTemplates()->with(['branch:id,name', 'room:id,name,number'])->get()]);
    }

    public function storeTemplate(ScheduleTemplateRequest $request, Doctor $doctor): JsonResponse
    {
        return response()->json(['data' => $this->config->saveTemplate($doctor, $request->validated())], 201);
    }

    public function updateTemplate(ScheduleTemplateRequest $request, Doctor $doctor, ScheduleTemplate $template): JsonResponse
    {
        abort_unless($template->doctor_id === $doctor->id, 404);

        return response()->json(['data' => $this->config->saveTemplate($doctor, $request->validated(), $template)]);
    }

    public function services(Doctor $doctor): JsonResponse
    {
        return response()->json(['data' => $doctor->services()->get()]);
    }

    public function storeService(DoctorServiceRequest $request, Doctor $doctor): JsonResponse
    {
        return response()->json(['data' => $this->config->saveService($doctor, $request->validated())], 201);
    }

    public function updateService(DoctorServiceRequest $request, Doctor $doctor, DoctorService $service): JsonResponse
    {
        abort_unless($service->doctor_id === $doctor->id, 404);

        return response()->json(['data' => $this->config->saveService($doctor, $request->validated(), $service)]);
    }

    public function blocks(Request $request): JsonResponse
    {
        $blocks = ScheduleBlock::query()->where('ends_at', '>=', now())
            ->when($request->query('doctor_id'), fn ($q, $v) => $q->where('doctor_id', $v))
            ->orderBy('starts_at')->limit(200)->get();

        return response()->json(['data' => $blocks]);
    }

    public function storeBlock(ScheduleBlockRequest $request): JsonResponse
    {
        $result = $this->config->createBlock($request->user(), $request->validated());

        return response()->json([
            'data' => $result['block'],
            'affected_appointments' => $result['affected']->map(fn ($a) => ['id' => $a->id, 'protocol' => $a->protocol, 'starts_at' => $a->starts_at->toIso8601String(), 'patient' => $a->patient?->displayName()]),
        ], 201);
    }

    public function destroyBlock(ScheduleBlock $block): JsonResponse
    {
        $block->delete();

        return response()->json(null, 204);
    }

    public function holidays(): JsonResponse
    {
        return response()->json(['data' => Holiday::query()->where('date', '>=', now()->subDays(30)->toDateString())->orderBy('date')->get()]);
    }

    public function storeHoliday(HolidayRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->config->saveHoliday($request->validated())], 201);
    }

    public function destroyHoliday(Holiday $holiday): JsonResponse
    {
        $holiday->delete();

        return response()->json(null, 204);
    }

    public function rooms(): JsonResponse
    {
        return response()->json(['data' => Room::query()->whereIn('branch_id', $this->branchIds())->orderBy('name')->get()]);
    }

    public function storeRoom(RoomRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->config->saveRoom($request->validated())], 201);
    }

    public function updateRoom(RoomRequest $request, Room $room): JsonResponse
    {
        abort_unless(in_array($room->branch_id, $this->branchIds(), true), 404);

        return response()->json(['data' => $this->config->saveRoom($request->validated(), $room)]);
    }

    private function branchIds(): array
    {
        return Branch::query()->accessible($this->context->allowedBranchIds())->pluck('id')->all();
    }
}
