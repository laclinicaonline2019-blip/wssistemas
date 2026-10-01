<?php

namespace App\Modules\Clinical\Http\Controllers\Web;

use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Clinical\Models\Medication;
use App\Modules\Clinical\Models\PatientAllergy;
use App\Modules\Clinical\Models\Triage;
use App\Modules\Clinical\Services\CidService;
use App\Modules\Clinical\Services\MedicationService;
use App\Modules\Clinical\Services\TriageService;
use App\Modules\Organization\Models\Branch;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Triagem, alergias, buscas de CID/medicamentos e cadastro de medicamentos da clínica. */
class ClinicalSupportController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    // ------------------------------------------------------------ Triagem

    public function triageIndex(Request $request): View
    {
        $branch = Branch::query()->active()->accessible($this->context->allowedBranchIds())
            ->when($request->query('branch_id', $this->context->branchId()), fn ($q, $id) => $q->whereKey($id))
            ->orderByDesc('is_headquarters')->firstOrFail();
        $tz = $branch->timezone;

        $appointments = Appointment::query()->with(['patient:id,name,social_name,birth_date,record_number', 'doctor:id,name,social_name', 'ticket'])
            ->where('branch_id', $branch->id)->whereIn('status', ['arrived', 'in_service'])
            ->whereBetween('starts_at', [CarbonImmutable::now($tz)->startOfDay()->utc(), CarbonImmutable::now($tz)->endOfDay()->utc()])
            ->orderBy('arrived_at')->get();

        $triaged = Triage::query()->whereIn('appointment_id', $appointments->pluck('id'))->pluck('risk', 'appointment_id');

        return view('clinical.triage-index', compact('branch', 'appointments', 'triaged'));
    }

    public function triageCreate(Request $request): View
    {
        $data = $request->validate(['patient_id' => ['required', 'string', 'size:26'], 'appointment_id' => ['nullable', 'string', 'size:26']]);

        return view('clinical.triage-form', [
            'patient' => Patient::query()->findOrFail($data['patient_id']),
            'appointment' => isset($data['appointment_id']) ? Appointment::query()->find($data['appointment_id']) : null,
            'allergies' => PatientAllergy::query()->where('patient_id', $data['patient_id'])->where('status', 'active')->get(),
        ]);
    }

    public function triageStore(Request $request, TriageService $service): RedirectResponse
    {
        // Aceita vírgula decimal ("36,5") antes de validar.
        foreach (['temperature', 'weight_kg'] as $decimal) {
            if (is_string($request->input($decimal))) {
                $request->merge([$decimal => str_replace(',', '.', trim($request->input($decimal)))]);
            }
        }

        $data = $request->validate([
            'patient_id' => ['required', 'string', 'size:26'],
            'appointment_id' => ['nullable', 'string', 'size:26'],
            'bp_systolic' => ['nullable', 'integer', 'between:40,300', 'required_with:bp_diastolic'],
            'bp_diastolic' => ['nullable', 'integer', 'between:20,200', 'required_with:bp_systolic', 'lt:bp_systolic'],
            'heart_rate' => ['nullable', 'integer', 'between:20,250'],
            'respiratory_rate' => ['nullable', 'integer', 'between:4,80'],
            'temperature' => ['nullable', 'numeric', 'between:30,45'],
            'spo2' => ['nullable', 'integer', 'between:40,100'],
            'weight_kg' => ['nullable', 'numeric', 'between:0.3,400'],
            'height_cm' => ['nullable', 'integer', 'between:20,250'],
            'glucose' => ['nullable', 'integer', 'between:10,1000'],
            'pain_scale' => ['nullable', 'integer', 'between:0,10'],
            'risk' => ['nullable', Rule::in(array_keys(Triage::RISKS))],
            'chief_complaint' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['bp_systolic' => 'PA sistólica', 'bp_diastolic' => 'PA diastólica', 'temperature' => 'temperatura', 'spo2' => 'saturação']);

        $service->record($request->user(), $data);

        return redirect()->route('triage.index')->with('success', 'Triagem registrada.');
    }

    // ------------------------------------------------------------ Alergias

    public function allergyStore(Request $request, Patient $patient, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('prontuario.editar') || $request->user()->hasPermission('triagem.registrar'), 403);
        $data = $request->validate([
            'substance' => ['required', 'string', 'max:150'],
            'reaction' => ['nullable', 'string', 'max:255'],
            'severity' => ['required', Rule::in(array_keys(PatientAllergy::SEVERITIES))],
        ]);

        PatientAllergy::record($patient->id, $data, $request->user()->id);

        return back()->with('success', 'Alergia registrada.');
    }

    public function allergyDeactivate(Request $request, PatientAllergy $allergy): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('prontuario.editar'), 403);
        $allergy->update(['status' => 'inactive']);

        return back()->with('success', 'Alergia marcada como inativa (o histórico é mantido).');
    }

    // ------------------------------------------------------------ Buscas (JSON)

    public function cidSearch(Request $request, CidService $cids): JsonResponse
    {
        $q = $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']])['q'];

        return response()->json(['data' => $cids->search($request->user(), $q)]);
    }

    public function cidFavorite(Request $request, CidService $cids, string $cid): JsonResponse
    {
        return response()->json(['favorite' => $cids->toggleFavorite($request->user(), $cid)]);
    }

    public function medicationSearch(Request $request): JsonResponse
    {
        $q = $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']])['q'];

        return response()->json(['data' => Medication::query()->search($q)->orderBy('active_ingredient')->limit(20)->get()->map(fn (Medication $m) => [
            'id' => $m->id, 'label' => $m->label(), 'default_posology' => $m->default_posology, 'route' => $m->route,
            'control_type' => $m->control_type, 'controlled' => $m->isControlled(),
        ])]);
    }

    // ------------------------------------------------------------ Medicamentos da clínica

    public function medications(Request $request): View
    {
        $request->validate(['q' => ['nullable', 'string', 'max:80']]);

        return view('clinical.medications', [
            'medications' => Medication::query()->when($request->query('q'), fn ($q, $t) => $q->search($t))
                ->orderBy('active_ingredient')->paginate(30)->withQueryString(),
        ]);
    }

    public function medicationStore(Request $request, MedicationService $service): RedirectResponse
    {
        $service->save($request->user(), $this->validateMedication($request));

        return back()->with('success', 'Medicamento cadastrado na base da clínica.');
    }

    public function medicationUpdate(Request $request, Medication $medication, MedicationService $service): RedirectResponse
    {
        $service->save($request->user(), $this->validateMedication($request) + ['is_active' => $request->boolean('is_active')], $medication);

        return back()->with('success', 'Medicamento atualizado.');
    }

    private function validateMedication(Request $request): array
    {
        return $request->validate([
            'active_ingredient' => ['required', 'string', 'max:200'],
            'commercial_name' => ['nullable', 'string', 'max:150'],
            'presentation' => ['nullable', 'string', 'max:120'],
            'concentration' => ['nullable', 'string', 'max:80'],
            'manufacturer' => ['nullable', 'string', 'max:120'],
            'route' => ['nullable', 'string', 'max:40'],
            'default_posology' => ['nullable', 'string', 'max:255'],
            'control_type' => ['required', Rule::in(array_keys(Medication::CONTROL_TYPES))],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['active_ingredient' => 'princípio ativo', 'control_type' => 'controle']);
    }
}
