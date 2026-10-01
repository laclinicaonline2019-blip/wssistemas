<?php

namespace App\Modules\Documents\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Http\DocumentRequests;
use App\Modules\Documents\Models\MedicalDocument;
use App\Modules\Documents\Services\DocumentPdf;
use App\Modules\Documents\Services\DocumentService;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function index(Request $request): JsonResponse
    {
        $patientId = $request->validate(['patient_id' => ['required', 'string', 'size:26']])['patient_id'];
        $types = array_values(array_filter(array_keys(MedicalDocument::TYPES), fn ($t) => $this->can($request->user(), $t, 'print')));
        abort_if($types === [], 403);

        $docs = MedicalDocument::query()->where('patient_id', $patientId)->whereIn('type', $types)->orderByDesc('issued_at')->paginate(30);

        return response()->json($docs->through(fn (MedicalDocument $d) => $this->summary($d)));
    }

    public function show(Request $request, MedicalDocument $document): JsonResponse
    {
        abort_unless($this->can($request->user(), $document->type, 'print', $document->branch_id), 403);

        return response()->json(['data' => $this->summary($document) + ['content' => $document->content, 'intact' => $this->documents->verify($document)]]);
    }

    public function store(Request $request): JsonResponse
    {
        $type = $request->validate(['type' => ['required', Rule::in(['prescription', 'certificate', 'exam_request', 'report'])]])['type'];
        abort_unless($this->can($request->user(), $type, 'issue'), 403);
        $data = $request->validate(DocumentRequests::rules($type), [], DocumentRequests::ATTRIBUTES);

        $docs = DocumentRequests::issue($this->documents, $request->user(), $type, $data);

        return response()->json(['data' => $docs->map(fn ($d) => $this->summary($d))->values()], 201);
    }

    public function cancel(Request $request, MedicalDocument $document): JsonResponse
    {
        abort_unless($this->can($request->user(), $document->type, 'cancel', $document->branch_id), 403);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']])['reason'];

        return response()->json(['data' => $this->summary($this->documents->cancel($request->user(), $document, $reason))]);
    }

    public function pdf(Request $request, MedicalDocument $document, DocumentPdf $pdf): Response
    {
        abort_unless($this->can($request->user(), $document->type, 'print', $document->branch_id), 403);
        $paper = $request->query('format') === 'a5' ? 'a5' : 'a4';
        $this->documents->registerPrint($document, 'pdf-'.$paper);

        return response($pdf->render(collect([$document]), $paper), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$pdf->filename($document).'"',
        ]);
    }

    private function summary(MedicalDocument $d): array
    {
        return [
            'id' => $d->id, 'type' => $d->type, 'subtype' => $d->subtype, 'label' => $d->typeLabel(), 'number' => $d->displayNumber(),
            'group_id' => $d->group_id, 'status' => $d->status, 'issued_at' => $d->issued_at->toIso8601String(),
            'valid_until' => $d->valid_until?->toDateString(), 'verification_code' => $d->formattedCode(),
            'validation_url' => $this->documents->validationUrl($d), 'print_count' => $d->print_count,
            'patient_id' => $d->patient_id, 'doctor_id' => $d->doctor_id, 'encounter_id' => $d->encounter_id,
        ];
    }

    private function can(User $user, string $type, string $action, ?string $branchId = null): bool
    {
        foreach ((array) MedicalDocument::permissionFor($type, $action) as $p) {
            if ($user->hasPermission($p, $branchId) || ($branchId === null && collect($user->allowedBranchIds() ?? [])->contains(fn ($b) => $user->hasPermission($p, $b)))) {
                return true;
            }
        }

        return false;
    }
}
