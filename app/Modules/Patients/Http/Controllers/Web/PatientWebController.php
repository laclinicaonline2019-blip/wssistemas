<?php

namespace App\Modules\Patients\Http\Controllers\Web;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Clinical\Models\Encounter;
use App\Modules\Clinical\Models\PatientAllergy;
use App\Modules\Documents\Models\MedicalDocument;
use App\Modules\Documents\Models\PatientFile;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Branch;
use App\Modules\Patients\Http\Controllers\Api\PatientController;
use App\Modules\Patients\Http\Requests\PatientRequest;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\PatientService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PatientWebController extends Controller
{
    public function __construct(
        private readonly PatientService $service,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): View
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'inactive'])]]);

        $patients = Patient::query()->search($request->query('search'))
            ->when($request->query('status', 'active'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('search_name')->paginate(25)->withQueryString();

        return view('patients.index', compact('patients'));
    }

    public function create(): View
    {
        return view('patients.form', ['patient' => new Patient, 'branches' => $this->branches()]);
    }

    public function store(PatientRequest $request): RedirectResponse
    {
        $patient = $this->service->create($request->user(), $request->validated(), $request->boolean('confirm_duplicate'));

        return redirect()->route('patients.show', $patient)->with('success', "Paciente cadastrado — prontuário nº {$patient->record_number}.");
    }

    public function show(Request $request, Patient $patient): View
    {
        $this->service->recordView($patient, 'web');
        $clinical = $request->user()->hasPermission('prontuario.visualizar');

        return view('patients.show', [
            'patient' => $patient->load(['contacts', 'insurances', 'consents.recorder:id,name', 'homeBranch']),
            'history' => $this->service->history($patient, 30),
            // Dados clínicos só para quem tem acesso ao prontuário (sigilo médico).
            'encounters' => $clinical ? Encounter::query()->with(['doctor:id,name,social_name', 'diagnoses', 'branch:id,name'])
                ->where('patient_id', $patient->id)->orderByDesc('started_at')->limit(30)->get() : null,
            'documents' => $this->documentsFor($request->user(), $patient),
            'files' => $clinical || $request->user()->hasPermission('documento.visualizar') || $request->user()->hasPermission('documento.anexar')
                ? PatientFile::query()->with('uploader:id,name')->where('patient_id', $patient->id)->orderBy('status')->orderByDesc('created_at')->limit(50)->get() : null,
            'allergies' => $clinical || $request->user()->hasPermission('triagem.registrar')
                ? PatientAllergy::query()->where('patient_id', $patient->id)->orderBy('status')->orderByDesc('created_at')->get() : null,
        ]);
    }

    public function edit(Patient $patient): View
    {
        abort_if($patient->isAnonymized(), 403, 'Paciente anonimizado: o cadastro não pode ser alterado.');

        return view('patients.form', ['patient' => $patient->load(['contacts', 'insurances']), 'branches' => $this->branches()]);
    }

    public function update(PatientRequest $request, Patient $patient): RedirectResponse
    {
        $this->service->update($request->user(), $patient, $request->validated() + ['contacts' => [], 'insurances' => []]);

        return redirect()->route('patients.show', $patient)->with('success', 'Cadastro atualizado.');
    }

    public function status(Request $request, Patient $patient): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);
        $this->service->setStatus($patient, $data['status']);

        return back()->with('success', $data['status'] === 'active' ? 'Paciente reativado.' : 'Paciente inativado.');
    }

    public function consent(Request $request, Patient $patient): RedirectResponse
    {
        $data = PatientController::validateConsent($request);
        $this->service->recordConsent($request->user(), $patient, $data['purpose'], (bool) $data['granted'], $data['channel'], $data['notes'] ?? null);

        return back()->with('success', 'Consentimento registrado.');
    }

    public function export(Request $request, Patient $patient): StreamedResponse
    {
        $data = $this->service->export($request->user(), $patient);

        return response()->streamDownload(
            fn () => print (json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            "paciente-{$patient->record_number}-dados.json",
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    public function anonymize(Request $request, Patient $patient): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:255'], 'confirm' => ['accepted']]);
        $this->service->anonymize($request->user(), $patient, $data['reason']);

        return redirect()->route('patients.show', $patient)->with('success', 'Paciente anonimizado.');
    }

    private function branches()
    {
        return Branch::query()->active()->accessible($this->context->allowedBranchIds())->orderByDesc('is_headquarters')->orderBy('name')->get(['id', 'name']);
    }

    /** Documentos do paciente que o usuário pode ver/imprimir (por tipo). */
    private function documentsFor(User $user, Patient $patient): ?Collection
    {
        $types = array_values(array_filter(array_keys(MedicalDocument::TYPES), function ($t) use ($user) {
            return collect((array) MedicalDocument::permissionFor($t, 'print'))->contains(fn ($p) => $user->hasPermission($p));
        }));

        return $types === [] ? null : MedicalDocument::query()->with('doctor:id,name,social_name')->where('patient_id', $patient->id)
            ->whereIn('type', $types)->orderByDesc('issued_at')->limit(20)->get();
    }
}
