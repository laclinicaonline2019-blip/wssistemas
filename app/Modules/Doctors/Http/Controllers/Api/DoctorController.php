<?php

namespace App\Modules\Doctors\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Doctors\Http\Requests\DoctorRequest;
use App\Modules\Doctors\Http\Resources\DoctorResource;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Services\DoctorService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class DoctorController extends Controller
{
    public function __construct(private readonly DoctorService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return DoctorResource::collection(self::query($request)->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    public static function query(Request $request): Builder
    {
        return Doctor::query()->with(['specialties', 'branches'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('specialty_id'), fn ($q, $id) => $q->whereHas('specialties', fn ($w) => $w->where('specialties.id', $id)))
            ->when($request->query('branch_id'), fn ($q, $id) => $q->whereHas('branches', fn ($w) => $w->where('branches.id', $id)))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w->whereLike('name', "%{$s}%")->orWhereLike('social_name', "%{$s}%")->orWhere('crm', preg_replace('/\D/', '', $s) ?: '-')))
            ->orderBy('name');
    }

    public function show(Doctor $doctor): DoctorResource
    {
        return new DoctorResource($doctor->load(['specialties', 'branches', 'user']));
    }

    public function store(DoctorRequest $request): DoctorResource
    {
        return new DoctorResource($this->service->create($request->user(), $request->validated()));
    }

    public function update(DoctorRequest $request, Doctor $doctor): DoctorResource
    {
        return new DoctorResource($this->service->update($request->user(), $doctor, $request->validated()));
    }

    public function status(Request $request, Doctor $doctor): DoctorResource
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);

        return new DoctorResource($this->service->setStatus($request->user(), $doctor, $data['status']));
    }
}
