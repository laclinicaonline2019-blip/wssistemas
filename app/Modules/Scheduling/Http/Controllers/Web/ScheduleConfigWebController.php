<?php

namespace App\Modules\Scheduling\Http\Controllers\Web;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Models\Specialty;
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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ScheduleConfigWebController extends Controller
{
    public function __construct(
        private readonly ScheduleConfigService $config,
        private readonly TenantContext $context,
    ) {}

    /** Agenda do médico: grade, serviços/valores, limite diário e bloqueios. */
    public function doctor(Doctor $doctor): View
    {
        return view('agenda.doctor-config', [
            'doctor' => $doctor->load(['scheduleTemplates.branch', 'scheduleTemplates.room', 'scheduleTemplates.specialty', 'services', 'branches', 'specialties']),
            'rooms' => Room::query()->active()->whereIn('branch_id', $doctor->branches->pluck('id'))->orderBy('name')->get(),
            'blocks' => ScheduleBlock::query()->where('doctor_id', $doctor->id)->where('ends_at', '>=', now())->orderBy('starts_at')->get(),
            'branches' => $this->branches()->whereIn('id', $doctor->branches->pluck('id')),
        ]);
    }

    public function storeTemplate(ScheduleTemplateRequest $request, Doctor $doctor): RedirectResponse
    {
        $this->config->saveTemplate($doctor, $request->validated());

        return back()->with('success', 'Período de atendimento incluído na grade.');
    }

    public function toggleTemplate(Doctor $doctor, ScheduleTemplate $template): RedirectResponse
    {
        abort_unless($template->doctor_id === $doctor->id, 404);
        $this->config->saveTemplate($doctor, ['is_active' => ! $template->is_active], $template);

        return back()->with('success', $template->is_active ? 'Período reativado.' : 'Período desativado.');
    }

    public function storeService(DoctorServiceRequest $request, Doctor $doctor): RedirectResponse
    {
        $this->config->saveService($doctor, $this->booleans($request, $request->validated(), ['accepts_private', 'accepts_insurance', 'is_telemedicine', 'is_return']));

        return back()->with('success', 'Tipo de atendimento cadastrado.');
    }

    public function updateService(DoctorServiceRequest $request, Doctor $doctor, DoctorService $service): RedirectResponse
    {
        abort_unless($service->doctor_id === $doctor->id, 404);
        $this->config->saveService($doctor, $this->booleans($request, $request->validated(), ['accepts_private', 'accepts_insurance', 'is_telemedicine', 'is_return', 'is_active']), $service);

        return back()->with('success', 'Tipo de atendimento atualizado.');
    }

    public function dailyLimit(Request $request, Doctor $doctor): RedirectResponse
    {
        $data = $request->validate(['daily_limit' => ['nullable', 'integer', 'min:1', 'max:200']]);
        $doctor->update(['daily_limit' => $data['daily_limit'] ?? null]);

        return back()->with('success', 'Limite diário atualizado.');
    }

    public function storeBlock(ScheduleBlockRequest $request): RedirectResponse
    {
        $result = $this->config->createBlock($request->user(), $request->validated());

        return back()->with('success', 'Bloqueio registrado.')->with('affected', $result['affected']->map(fn ($a) => [
            'id' => $a->id, 'protocol' => $a->protocol, 'when' => $a->starts_at->setTimezone('America/Sao_Paulo')->format('d/m H:i'),
            'patient' => $a->patient?->displayName(), 'phone' => $a->patient?->whatsapp ?? $a->patient?->phone,
        ])->all());
    }

    public function destroyBlock(ScheduleBlock $block): RedirectResponse
    {
        $block->delete();

        return back()->with('success', 'Bloqueio removido.');
    }

    public function holidays(): View
    {
        return view('agenda.holidays', [
            'holidays' => Holiday::query()->with('branch:id,name')->where('date', '>=', now()->subMonths(1)->toDateString())->orderBy('date')->get(),
            'branches' => $this->branches(),
            'blocks' => ScheduleBlock::query()->with(['doctor:id,name,social_name', 'branch:id,name'])->whereNull('doctor_id')->where('ends_at', '>=', now())->orderBy('starts_at')->get(),
        ]);
    }

    public function storeHoliday(HolidayRequest $request): RedirectResponse
    {
        $this->config->saveHoliday($request->validated());

        return back()->with('success', 'Feriado cadastrado.');
    }

    public function destroyHoliday(Holiday $holiday): RedirectResponse
    {
        $holiday->delete();

        return back()->with('success', 'Feriado removido.');
    }

    public function rooms(): View
    {
        $branches = $this->branches();

        return view('agenda.rooms', [
            'rooms' => Room::query()->with(['branch:id,name', 'doctor:id,name,social_name', 'specialty:id,name'])->whereIn('branch_id', $branches->pluck('id'))->orderBy('name')->get(),
            'branches' => $branches,
            'doctors' => Doctor::query()->active()->orderBy('name')->get(['id', 'name', 'social_name']),
            'specialties' => Specialty::query()->active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storeRoom(RoomRequest $request): RedirectResponse
    {
        $this->config->saveRoom($request->validated());

        return back()->with('success', 'Sala cadastrada.');
    }

    public function updateRoom(RoomRequest $request, Room $room): RedirectResponse
    {
        abort_unless($this->branches()->pluck('id')->contains($room->branch_id), 404);
        $this->config->saveRoom($request->validated(), $room);

        return back()->with('success', 'Sala atualizada.');
    }

    private function branches()
    {
        return Branch::query()->active()->accessible($this->context->allowedBranchIds())->orderByDesc('is_headquarters')->orderBy('name')->get(['id', 'name', 'timezone']);
    }

    /** Checkboxes desmarcados não são enviados pelo navegador. */
    private function booleans(Request $request, array $data, array $keys): array
    {
        foreach ($keys as $key) {
            $data[$key] = $request->boolean($key);
        }

        return $data;
    }
}
