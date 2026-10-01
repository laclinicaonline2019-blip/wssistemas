<?php

namespace App\Modules\Clinical\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Clinical\Models\Encounter;
use App\Modules\Clinical\Services\EncounterService;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Prontuário via API (apps e integrações). Mesmas regras da interface web. */
class EncounterController extends Controller
{
    public function __construct(private readonly EncounterService $encounters) {}

    /** Linha do tempo clínica do paciente (somente atendimentos finalizados). */
    public function index(Request $request): JsonResponse
    {
        $patientId = $request->validate(['patient_id' => ['required', 'string', 'size:26']])['patient_id'];
        Patient::query()->findOrFail($patientId);

        $items = Encounter::query()->with(['doctor:id,name,social_name', 'diagnoses'])
            ->where('patient_id', $patientId)->where('status', 'finalized')->orderByDesc('started_at')->paginate(30);

        return response()->json($items->through(fn (Encounter $e) => [
            'id' => $e->id, 'started_at' => $e->started_at->toIso8601String(), 'finalized_at' => $e->finalized_at?->toIso8601String(),
            'doctor' => $e->doctor?->displayName(), 'current_version' => $e->current_version,
            'diagnoses' => $e->diagnoses->where('version', $e->current_version)->map->only(['code', 'description', 'is_primary'])->values(),
        ]));
    }

    public function show(Encounter $encounter): JsonResponse
    {
        $this->encounters->recordView($encounter, 'api');
        $encounter->load(['versions', 'doctor:id,name,social_name,crm,crm_state', 'patient:id,name,social_name,record_number']);

        return response()->json(['data' => [
            'id' => $encounter->id,
            'status' => $encounter->status,
            'patient' => ['id' => $encounter->patient->id, 'name' => $encounter->patient->displayName(), 'record_number' => $encounter->patient->record_number],
            'doctor' => ['id' => $encounter->doctor->id, 'name' => $encounter->doctor->displayName(), 'registration' => $encounter->doctor->registration()],
            'started_at' => $encounter->started_at->toIso8601String(),
            'finalized_at' => $encounter->finalized_at?->toIso8601String(),
            'draft' => $encounter->isDraft() ? ['data' => $encounter->draft_data, 'revision' => $encounter->draft_revision] : null,
            'versions' => $encounter->versions->map(fn ($v) => [
                'version' => $v->version, 'kind' => $v->kind, 'data' => $v->data, 'diagnoses' => $v->diagnoses,
                'reason' => $v->reason, 'author_id' => $v->author_id, 'created_at' => $v->created_at->toIso8601String(), 'hash' => $v->hash,
            ]),
            'integrity' => $encounter->isDraft() ? null : $this->encounters->verify($encounter),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'appointment_id' => ['nullable', 'required_without:patient_id', 'string', 'size:26'],
            'patient_id' => ['nullable', 'string', 'size:26'],
            'branch_id' => ['nullable', 'required_with:patient_id', 'string', 'size:26'],
        ]);

        $encounter = isset($data['appointment_id'])
            ? $this->encounters->startFromAppointment($request->user(), Appointment::query()->findOrFail($data['appointment_id']))
            : $this->encounters->startWalkIn($request->user(), Patient::query()->findOrFail($data['patient_id']), $data['branch_id']);

        return response()->json(['data' => ['id' => $encounter->id, 'status' => $encounter->status, 'draft' => $encounter->draft_data, 'revision' => $encounter->draft_revision]], 201);
    }

    public function draft(Request $request, Encounter $encounter): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'data' => ['required', 'array']]);

        return response()->json($this->encounters->saveDraft($request->user(), $encounter, $data['data'], $data['revision']));
    }

    public function finalize(Request $request, Encounter $encounter): JsonResponse
    {
        $data = $request->validate(['revision' => ['nullable', 'integer', 'min:0'], 'data' => ['nullable', 'array']]);
        $encounter = $this->encounters->finalize($request->user(), $encounter, $data['data'] ?? null, isset($data['revision']) ? (int) $data['revision'] : null);

        return response()->json(['data' => ['id' => $encounter->id, 'status' => $encounter->status, 'version' => $encounter->current_version, 'hash' => $encounter->latestVersion?->hash]]);
    }

    public function addendum(Request $request, Encounter $encounter): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500'], 'data' => ['required', 'array']]);
        $version = $this->encounters->addendum($request->user(), $encounter, $data['data'], $data['reason']);

        return response()->json(['data' => ['version' => $version->version, 'hash' => $version->hash]], 201);
    }
}
