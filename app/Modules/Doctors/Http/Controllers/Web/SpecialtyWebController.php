<?php

namespace App\Modules\Doctors\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Doctors\Http\Requests\SpecialtyRequest;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Doctors\Services\SpecialtyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SpecialtyWebController extends Controller
{
    public function __construct(private readonly SpecialtyService $service) {}

    public function index(): View
    {
        return view('specialties.index', ['specialties' => Specialty::query()->withCount('doctors')->orderBy('name')->get()]);
    }

    public function store(SpecialtyRequest $request): RedirectResponse
    {
        $this->service->create($request->validated());

        return back()->with('success', 'Especialidade cadastrada.');
    }

    public function update(SpecialtyRequest $request, Specialty $specialty): RedirectResponse
    {
        $this->service->update($specialty, $request->validated() + ['is_active' => $request->boolean('is_active')]);

        return back()->with('success', 'Especialidade atualizada.');
    }
}
