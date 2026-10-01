<?php

namespace App\Modules\Documents\Http\Controllers\Web;

use App\Core\Support\BusinessRuleViolation;
use App\Http\Controllers\Controller;
use App\Modules\Clinical\Models\Encounter;
use App\Modules\Clinical\Models\Medication;
use App\Modules\Clinical\Models\PatientAllergy;
use App\Modules\Documents\Http\DocumentRequests;
use App\Modules\Documents\Models\MedicalDocument;
use App\Modules\Documents\Services\DocumentPdf;
use App\Modules\Documents\Services\DocumentService;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Receitas, atestados, solicitações de exames e documentos: emissão, impressão (A4/A5/térmica/PDF) e cancelamento. */
class DocumentWebController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $filters = $request->validate([
            'patient_id' => ['nullable', 'string', 'size:26'],
            'type' => ['nullable', Rule::in(array_keys(MedicalDocument::TYPES))],
        ]);
        $types = $this->printableTypes($user);
        abort_if($types === [], 403);

        $docs = MedicalDocument::query()->with(['patient:id,name,social_name,record_number', 'doctor:id,name,social_name'])
            ->whereIn('type', $types)
            ->when($filters['patient_id'] ?? null, fn ($q, $id) => $q->where('patient_id', $id))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->orderByDesc('issued_at')->orderByDesc('number')->paginate(30)->withQueryString();

        return view('documents.index', [
            'docs' => $docs, 'types' => $types,
            'patient' => isset($filters['patient_id']) ? Patient::query()->find($filters['patient_id']) : null,
        ]);
    }

    public function create(Request $request): View
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['prescription', 'certificate', 'exam_request', 'report'])],
            'patient_id' => ['required', 'string', 'size:26'],
            'encounter_id' => ['nullable', 'string', 'size:26'],
        ]);
        $this->authorizeType($request->user(), $data['type'], 'issue');

        $patient = Patient::query()->findOrFail($data['patient_id']);
        $encounter = isset($data['encounter_id']) ? Encounter::query()->where('patient_id', $patient->id)->findOrFail($data['encounter_id']) : null;

        return view('documents.create', [
            'type' => $data['type'], 'patient' => $patient, 'encounter' => $encounter,
            'allergies' => PatientAllergy::query()->where('patient_id', $patient->id)->where('status', 'active')->get(),
            'controlTypes' => Medication::CONTROL_TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $type = $request->validate(['type' => ['required', Rule::in(['prescription', 'certificate', 'exam_request', 'report'])]])['type'];
        $this->authorizeType($request->user(), $type, 'issue');

        if ($type === 'exam_request' && $request->has('exams_text')) {
            $request->merge(['exams' => array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $request->input('exams_text')))))]);
        }

        $data = $request->validate(DocumentRequests::rules($type), [], DocumentRequests::ATTRIBUTES);

        $docs = DocumentRequests::issue($this->documents, $request->user(), $type, $data);

        return redirect()->route('documents.show', $docs->first())
            ->with('success', $docs->count() > 1 ? "{$docs->count()} documentos emitidos (separados conforme a legislação)." : 'Documento emitido.');
    }

    public function show(Request $request, MedicalDocument $document): View
    {
        $this->authorizeType($request->user(), $document->type, 'print', $document->branch_id);
        $group = MedicalDocument::query()->where('group_id', $document->group_id)->orderBy('number')->get()
            ->filter(fn ($d) => $this->can($request->user(), $d->type, 'print', $d->branch_id));

        return view('documents.show', [
            'document' => $document->load(['patient', 'doctor', 'issuer:id,name', 'canceller:id,name']),
            'group' => $group,
            'intact' => $this->documents->verify($document),
            'canCancel' => $this->can($request->user(), $document->type, 'cancel', $document->branch_id),
        ]);
    }

    /** Impressão no navegador. ?preview=1 não conta como impressão (pré-visualização na tela). */
    public function print(Request $request, MedicalDocument $document): View
    {
        $this->authorizeType($request->user(), $document->type, 'print', $document->branch_id);

        return $this->renderPrint($request, collect([$document]));
    }

    /** Imprime todos os documentos emitidos juntos (ex.: receita simples + controle especial). */
    public function printGroup(Request $request, string $group): View
    {
        $docs = MedicalDocument::query()->where('group_id', $group)->where('status', 'issued')->orderBy('number')->get()
            ->filter(fn ($d) => $this->can($request->user(), $d->type, 'print', $d->branch_id))->values();
        abort_if($docs->isEmpty(), 404);

        return $this->renderPrint($request, $docs);
    }

    public function pdf(Request $request, MedicalDocument $document, DocumentPdf $pdf): Response
    {
        $this->authorizeType($request->user(), $document->type, 'print', $document->branch_id);
        $paper = $request->query('format') === 'a5' ? 'a5' : 'a4';
        $this->documents->registerPrint($document, 'pdf-'.$paper);

        return response($pdf->render(collect([$document]), $paper), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$pdf->filename($document).'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function cancel(Request $request, MedicalDocument $document): RedirectResponse
    {
        $this->authorizeType($request->user(), $document->type, 'cancel', $document->branch_id);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']], [], ['reason' => 'motivo'])['reason'];
        $this->documents->cancel($request->user(), $document, $reason);

        return redirect()->route('documents.show', $document)->with('success', 'Documento cancelado. Ele continua no histórico do paciente e a validação pública passa a indicar o cancelamento.');
    }

    private function renderPrint(Request $request, Collection $docs): View
    {
        $format = in_array($request->query('format'), ['a4', 'a5', 'thermal'], true) ? $request->query('format') : 'a4';
        $preview = $request->boolean('preview');
        $docs->each->loadMissing('branch:id,timezone');

        if ($format === 'thermal' && $docs->contains(fn ($d) => ! $d->allowsThermal())) {
            throw new BusinessRuleViolation('Receita de controle especial e registros de notificação não podem ser impressos na térmica — use A4 ou A5.', 'thermal_not_allowed');
        }

        if (! $preview) {
            $docs->each(fn ($d) => $this->documents->registerPrint($d, $format));
        }

        return view('documents.print', [
            'docs' => $docs, 'format' => $format, 'preview' => $preview, 'pdf' => false,
            'thermalWidth' => (int) (Company::query()->find($docs->first()->company_id)?->setting('print.thermal_width_mm', 80) ?? 80),
            'service' => $this->documents,
        ]);
    }

    /** @return list<string> */
    private function printableTypes(User $user): array
    {
        return array_values(array_filter(array_keys(MedicalDocument::TYPES), fn ($t) => $this->can($user, $t, 'print')));
    }

    private function can(User $user, string $type, string $action, ?string $branchId = null): bool
    {
        foreach ((array) MedicalDocument::permissionFor($type, $action) as $permission) {
            if ($branchId ? $user->hasPermission($permission, $branchId) : $this->hasAnywhere($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    private function hasAnywhere(User $user, string $permission): bool
    {
        return $user->hasPermission($permission) || collect($user->allowedBranchIds() ?? [])->contains(fn ($b) => $user->hasPermission($permission, $b));
    }

    private function authorizeType(User $user, string $type, string $action, ?string $branchId = null): void
    {
        abort_unless($this->can($user, $type, $action, $branchId), 403);
    }
}
