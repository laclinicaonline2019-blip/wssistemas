<?php

namespace App\Modules\Clinical\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Clinical\Http\Controllers\Web\ClinicalSupportController;
use App\Modules\Clinical\Models\PatientAllergy;
use App\Modules\Clinical\Models\Triage;
use App\Modules\Clinical\Services\CidService;
use App\Modules\Clinical\Services\TriageService;
use App\Modules\Patients\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClinicalController extends Controller
{
    public function triages(Request $request): JsonResponse
    {
        $patientId = $request->validate(['patient_id' => ['required', 'string', 'size:26']])['patient_id'];

        return response()->json(['data' => Triage::query()->where('patient_id', $patientId)->latest('created_at')->limit(30)->get()
            ->map(fn (Triage $t) => $t->toArray() + ['summary' => $t->summary(), 'bmi' => $t->bmi()])]);
    }

    public function storeTriage(Request $request, TriageService $service): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'string', 'size:26'], 'appointment_id' => ['nullable', 'string', 'size:26'], 'branch_id' => ['nullable', 'string', 'size:26'],
            'bp_systolic' => ['nullable', 'integer', 'between:40,300', 'required_with:bp_diastolic'], 'bp_diastolic' => ['nullable', 'integer', 'between:20,200', 'required_with:bp_systolic'],
            'heart_rate' => ['nullable', 'integer', 'between:20,250'], 'respiratory_rate' => ['nullable', 'integer', 'between:4,80'],
            'temperature' => ['nullable', 'numeric', 'between:30,45'], 'spo2' => ['nullable', 'integer', 'between:40,100'],
            'weight_kg' => ['nullable', 'numeric', 'between:0.3,400'], 'height_cm' => ['nullable', 'integer', 'between:20,250'],
            'glucose' => ['nullable', 'integer', 'between:10,1000'], 'pain_scale' => ['nullable', 'integer', 'between:0,10'],
            'risk' => ['nullable', Rule::in(array_keys(Triage::RISKS))], 'chief_complaint' => ['nullable', 'string', 'max:500'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $triage = $service->record($request->user(), $data);

        return response()->json(['data' => $triage->toArray() + ['summary' => $triage->summary()]], 201);
    }

    public function allergies(Patient $patient): JsonResponse
    {
        return response()->json(['data' => PatientAllergy::query()->where('patient_id', $patient->id)->orderBy('status')->orderBy('substance')->get()]);
    }

    public function storeAllergy(Request $request, Patient $patient): JsonResponse
    {
        abort_unless($request->user()->hasPermission('prontuario.editar') || $request->user()->hasPermission('triagem.registrar'), 403);
        $data = $request->validate([
            'substance' => ['required', 'string', 'max:150'], 'reaction' => ['nullable', 'string', 'max:255'],
            'severity' => ['required', Rule::in(array_keys(PatientAllergy::SEVERITIES))],
        ]);

        return response()->json(['data' => PatientAllergy::record($patient->id, $data, $request->user()->id)], 201);
    }

    public function cid(Request $request): JsonResponse
    {
        return app(ClinicalSupportController::class)->cidSearch($request, app(CidService::class));
    }

    public function medications(Request $request): JsonResponse
    {
        return app(ClinicalSupportController::class)->medicationSearch($request);
    }
}
