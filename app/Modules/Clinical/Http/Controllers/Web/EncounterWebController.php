<?php

namespace App\Modules\Clinical\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Clinical\Models\Encounter;
use App\Modules\Clinical\Models\Medication;
use App\Modules\Clinical\Models\PatientAllergy;
use App\Modules\Clinical\Models\Triage;
use App\Modules\Clinical\Services\CidService;
use App\Modules\Clinical\Services\EncounterService;
use App\Modules\Documents\Models\MedicalDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EncounterWebController extends Controller
{
    public function __construct(private readonly EncounterService $encounters) {}

    /** Editor do atendimento (rascunho). Finalizado → visualização. */
    public function edit(Request $request, Encounter $encounter, CidService $cids): View|RedirectResponse
    {
        if (! $encounter->isDraft()) {
            return redirect()->route('encounters.show', $encounter);
        }

        abort_unless($this->encounters->isAuthor($request->user(), $encounter), 403, 'Somente o médico responsável pode editar este atendimento.');
        $this->encounters->recordView($encounter, 'web');

        return view('clinical.encounter-edit', [
            'encounter' => $encounter->load(['patient.insurances', 'doctor', 'branch', 'appointment.service']),
            ...$this->context($encounter),
            'shortcuts' => $cids->shortcuts($request->user()),
        ]);
    }

    public function autosave(Request $request, Encounter $encounter): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'data' => ['required', 'array']]);

        return response()->json($this->encounters->saveDraft($request->user(), $encounter, $data['data'], $data['revision']));
    }

    public function finalize(Request $request, Encounter $encounter): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'data' => ['required', 'array']]);
        $this->encounters->finalize($request->user(), $encounter, $data['data'], (int) $data['revision']);

        return redirect()->route('encounters.show', $encounter)->with('success', 'Atendimento finalizado e registrado no prontuário.');
    }

    public function show(Request $request, Encounter $encounter): View
    {
        $this->encounters->recordView($encounter, 'web');
        $encounter->load(['patient', 'doctor', 'branch', 'specialty', 'versions.author:id,name', 'versions.authorDoctor']);

        return view('clinical.encounter-show', [
            'encounter' => $encounter,
            'integrity' => $encounter->isDraft() ? null : $this->encounters->verify($encounter),
            'isAuthor' => $this->encounters->isAuthor($request->user(), $encounter),
            ...$this->context($encounter),
        ]);
    }

    public function addendumForm(Request $request, Encounter $encounter, CidService $cids): View
    {
        abort_if($encounter->isDraft(), 404);
        abort_unless($this->encounters->isAuthor($request->user(), $encounter), 403, 'Somente o médico responsável pode registrar adendo.');

        return view('clinical.encounter-edit', [
            'encounter' => $encounter->load(['patient.insurances', 'doctor', 'branch', 'latestVersion']),
            'addendum' => true,
            ...$this->context($encounter),
            'shortcuts' => $cids->shortcuts($request->user()),
        ]);
    }

    public function storeAddendum(Request $request, Encounter $encounter): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500'], 'data' => ['required', 'array']]);
        $version = $this->encounters->addendum($request->user(), $encounter, $data['data'], $data['reason']);

        return redirect()->route('encounters.show', $encounter)->with('success', "Adendo registrado (versão {$version->version}).");
    }

    /** Alergias, triagem do dia e histórico do paciente — contexto clínico do editor e da visualização. */
    private function context(Encounter $encounter): array
    {
        return [
            'allergies' => PatientAllergy::query()->where('patient_id', $encounter->patient_id)->where('status', 'active')->get(),
            'triage' => Triage::query()->where('patient_id', $encounter->patient_id)
                ->where('created_at', '>=', $encounter->started_at->copy()->subHours(12))->latest('created_at')->first(),
            'history' => Encounter::query()->with(['doctor:id,name,social_name', 'latestVersion', 'diagnoses'])
                ->where('patient_id', $encounter->patient_id)->where('status', 'finalized')->whereKeyNot($encounter->id)
                ->orderByDesc('started_at')->limit(10)->get(),
            'controlTypes' => Medication::CONTROL_TYPES,
            'documents' => MedicalDocument::query()->where('encounter_id', $encounter->id)->orderBy('number')->get(),
        ];
    }
}
