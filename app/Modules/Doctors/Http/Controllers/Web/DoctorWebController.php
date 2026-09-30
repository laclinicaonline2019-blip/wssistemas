<?php

namespace App\Modules\Doctors\Http\Controllers\Web;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Doctors\Http\Controllers\Api\DoctorController;
use App\Modules\Doctors\Http\Requests\DoctorRequest;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Doctors\Services\DoctorService;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DoctorWebController extends Controller
{
    public function __construct(
        private readonly DoctorService $service,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): View
    {
        return view('doctors.index', [
            'doctors' => DoctorController::query($request)->paginate(25)->withQueryString(),
            'specialties' => Specialty::query()->active()->orderBy('name')->get(['id', 'name']),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): View
    {
        return view('doctors.form', ['doctor' => new Doctor, ...$this->formData()]);
    }

    public function store(DoctorRequest $request): RedirectResponse
    {
        $doctor = $this->service->create($request->user(), $request->validated() + ['specialties' => [], 'branches' => []]);

        return redirect()->route('doctors.edit', $doctor)->with('success', 'Médico cadastrado.');
    }

    public function edit(Request $request, Doctor $doctor): View
    {
        return view('doctors.form', [
            'doctor' => $doctor->load(['specialties', 'branches']),
            'canManage' => $this->service->canManage($request->user(), $doctor),
            ...$this->formData(),
        ]);
    }

    public function update(DoctorRequest $request, Doctor $doctor): RedirectResponse
    {
        $this->service->update($request->user(), $doctor, $request->validated() + ['specialties' => [], 'branches' => []]);

        return back()->with('success', 'Cadastro do médico atualizado.');
    }

    public function status(Request $request, Doctor $doctor): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);
        $this->service->setStatus($request->user(), $doctor, $data['status']);

        return back()->with('success', $data['status'] === 'active' ? 'Médico reativado.' : 'Médico desativado.');
    }

    private function formData(): array
    {
        return [
            'specialties' => Specialty::query()->active()->orderBy('name')->get(),
            'branches' => Branch::query()->active()->accessible($this->context->allowedBranchIds())->orderByDesc('is_headquarters')->orderBy('name')->get(),
            'users' => User::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'email']),
            'canManage' => true,
        ];
    }
}
