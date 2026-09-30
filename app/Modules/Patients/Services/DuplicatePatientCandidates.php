<?php

namespace App\Modules\Patients\Services;

use App\Core\Support\Format;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RuntimeException;

/** Possíveis pacientes duplicados: exige confirmação explícita para prosseguir. */
class DuplicatePatientCandidates extends RuntimeException
{
    public function __construct(public readonly Collection $candidates)
    {
        parent::__construct('Encontramos pacientes parecidos. Confira antes de cadastrar um novo.');
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        $list = $this->candidates->map(fn ($p) => [
            'id' => $p->id,
            'record_number' => $p->record_number,
            'name' => $p->name,
            'birth_date' => $p->birth_date?->format('Y-m-d'),
            'cpf_masked' => Format::cpfMasked($p->cpf),
        ])->values();

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $this->getMessage(), 'code' => 'possible_duplicate', 'candidates' => $list], 409);
        }

        return back()->withInput()->with('duplicates', $list->all());
    }
}
