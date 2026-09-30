<?php

namespace App\Modules\Doctors\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Doctors\Http\Requests\SpecialtyRequest;
use App\Modules\Doctors\Http\Resources\SpecialtyResource;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Doctors\Services\SpecialtyService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SpecialtyController extends Controller
{
    public function __construct(private readonly SpecialtyService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return SpecialtyResource::collection(
            Specialty::query()->withCount('doctors')
                ->when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
                ->orderBy('name')->get()
        );
    }

    public function store(SpecialtyRequest $request): SpecialtyResource
    {
        return new SpecialtyResource($this->service->create($request->validated()));
    }

    public function update(SpecialtyRequest $request, Specialty $specialty): SpecialtyResource
    {
        return new SpecialtyResource($this->service->update($specialty, $request->validated()));
    }
}
