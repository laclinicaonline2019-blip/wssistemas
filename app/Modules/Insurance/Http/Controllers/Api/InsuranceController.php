<?php

namespace App\Modules\Insurance\Http\Controllers\Api;

use App\Core\Tenancy\TenantContext;
use App\Core\Validation\ExistsInTenant;
use App\Http\Controllers\Controller;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Insurance\Models\Authorization;
use App\Modules\Insurance\Models\Batch;
use App\Modules\Insurance\Models\Guide;
use App\Modules\Insurance\Models\Insurer;
use App\Modules\Insurance\Models\Procedure;
use App\Modules\Insurance\Services\GuideService;
use App\Modules\Insurance\Services\InsuranceCatalog;
use App\Modules\Organization\Models\Branch;
use App\Modules\Patients\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** API de convênios. Valores em centavos (*_cents). */
class InsuranceController extends Controller
{
    public function __construct(private readonly GuideService $guides, private readonly InsuranceCatalog $catalog, private readonly TenantContext $context) {}

    public function insurers(): JsonResponse
    {
        return response()->json(['data' => Insurer::query()->with(['plans' => fn ($q) => $q->where('is_active', true)])->where('is_active', true)->orderBy('name')->get()
            ->map(fn (Insurer $i) => $i->only(['id', 'name', 'ans_registry', 'tiss_version', 'payment_term_days']) + [
                'plans' => $i->plans->map->only(['id', 'name', 'ans_code'])->values(),
                'credentialed_doctor_ids' => $i->doctors()->pluck('doctors.id'),
            ])]);
    }

    public function procedures(Request $request): JsonResponse
    {
        $q = $request->validate(['q' => ['nullable', 'string', 'max:60']])['q'] ?? null;

        return response()->json(Procedure::query()->where('is_active', true)
            ->when($q, fn ($w) => $w->where(fn ($x) => $x->where('code', 'like', addcslashes($q, '%_\\').'%')->orWhere('name', 'like', '%'.addcslashes($q, '%_\\').'%')))
            ->orderBy('code')->paginate(50)->through(fn (Procedure $p) => $p->only(['id', 'table_code', 'code', 'name', 'kind'])));
    }

    /** Valor vigente do procedimento no convênio/plano (consulta para o balcão/IA). */
    public function price(Request $request): JsonResponse
    {
        $f = $request->validate([
            'insurer_id' => ['required', 'string', 'size:26', new ExistsInTenant(Insurer::class)],
            'plan_id' => ['nullable', 'string', 'size:26'],
            'procedure_id' => ['required', 'string', 'size:26', new ExistsInTenant(Procedure::class)],
            'date' => ['nullable', 'date'],
        ]);
        $item = $this->catalog->priceFor($f['insurer_id'], $f['plan_id'] ?? null, $f['procedure_id'], substr($f['date'] ?? now('America/Sao_Paulo')->toDateString(), 0, 10));

        return response()->json(['data' => $item ? [
            'price_cents' => $item->price_cents, 'requires_authorization' => $item->requires_authorization,
            'copay_type' => $item->copay_type, 'copay_value' => $item->copay_value, 'price_table_id' => $item->price_table_id,
        ] : null]);
    }

    public function authorizations(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(array_keys(Authorization::STATUSES))], 'patient_id' => ['nullable', 'string', 'size:26']]);

        return response()->json(Authorization::query()->when($this->context->allowedBranchIds() !== null, fn ($q) => $q->whereIn('branch_id', $this->context->allowedBranchIds()))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['patient_id'] ?? null, fn ($q, $id) => $q->where('patient_id', $id))
            ->latest()->paginate(50)->through(fn (Authorization $a) => $this->authorization($a)));
    }

    public function storeAuthorization(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'string', 'size:26', new ExistsInTenant(Patient::class)],
            'patient_insurance_id' => ['required', 'string', 'size:26'],
            'branch_id' => ['required', 'string', 'size:26', Rule::in(Branch::query()->active()->accessible($this->context->allowedBranchIds())->pluck('id'))],
            'procedure_id' => ['required', 'string', 'size:26', new ExistsInTenant(Procedure::class)],
            'doctor_id' => ['nullable', 'string', 'size:26', new ExistsInTenant(Doctor::class)],
            'quantity' => ['nullable', 'integer', 'between:1,999'],
            'operator_guide_number' => ['nullable', 'string', 'max:20'], 'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json(['data' => $this->authorization($this->guides->requestAuthorization($request->user(), $data))], 201);
    }

    public function decideAuthorization(Request $request, Authorization $authorization): JsonResponse
    {
        $this->assertBranch($authorization->branch_id);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['authorized', 'denied'])],
            'password' => ['nullable', 'string', 'max:20'], 'operator_guide_number' => ['nullable', 'string', 'max:20'],
            'authorized_on' => ['nullable', 'date'], 'valid_until' => ['nullable', 'date'], 'denial_reason' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $this->authorization($this->guides->decideAuthorization($request->user(), $authorization, $data))]);
    }

    public function guides(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(array_keys(Guide::STATUSES))], 'insurer_id' => ['nullable', 'string', 'size:26'], 'patient_id' => ['nullable', 'string', 'size:26']]);

        return response()->json(Guide::query()->accessibleBranches($this->context->allowedBranchIds())
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['insurer_id'] ?? null, fn ($q, $id) => $q->where('insurer_id', $id))
            ->when($f['patient_id'] ?? null, fn ($q, $id) => $q->where('patient_id', $id))
            ->orderByDesc('attendance_date')->paginate(50)->through(fn (Guide $g) => $this->guideData($g)));
    }

    public function guide(Guide $guide): JsonResponse
    {
        $this->assertBranch($guide->branch_id);

        return response()->json(['data' => $this->guideData($guide->load('items')) + [
            'items' => $guide->items->map(fn ($i) => $i->only(['id', 'table_code', 'code', 'description', 'quantity', 'unit_cents', 'total_cents', 'requires_authorization'])
                + ['execution_date' => $i->execution_date->toDateString()])->values(),
            'issues' => $guide->isEditable() ? $this->guides->issues($guide) : [],
        ]]);
    }

    public function ready(Guide $guide): JsonResponse
    {
        $this->assertBranch($guide->branch_id);

        return response()->json(['data' => $this->guideData($this->guides->markReady($guide))]);
    }

    public function batches(Request $request): JsonResponse
    {
        return response()->json(Batch::query()->accessibleBranches($this->context->allowedBranchIds())->latest()->paginate(30)
            ->through(fn (Batch $b) => $this->batch($b)));
    }

    public function batchShow(Batch $batch): JsonResponse
    {
        $this->assertBranch($batch->branch_id);

        return response()->json(['data' => $this->batch($batch) + ['guide_ids' => Guide::query()->where('batch_id', $batch->id)->pluck('id')]]);
    }

    public function batchXml(Batch $batch): Response
    {
        $this->assertBranch($batch->branch_id);
        abort_unless($batch->xml, 404);

        return response($batch->xmlFile(), 200, ['Content-Type' => 'application/xml; charset=ISO-8859-1']);
    }

    private function guideData(Guide $g): array
    {
        return $g->only(['id', 'number', 'guide_type', 'status', 'insurer_id', 'plan_id', 'patient_id', 'doctor_id', 'appointment_id', 'batch_id',
            'authorization_id', 'operator_guide_number', 'total_cents', 'paid_cents', 'glosa_cents', 'glosa_status']) + ['attendance_date' => $g->attendance_date->toDateString()];
    }

    private function authorization(Authorization $a): array
    {
        return $a->only(['id', 'patient_id', 'patient_insurance_id', 'insurer_id', 'procedure_id', 'doctor_id', 'quantity', 'status', 'password', 'operator_guide_number', 'denial_reason'])
            + ['authorized_on' => $a->authorized_on?->toDateString(), 'valid_until' => $a->valid_until?->toDateString()];
    }

    private function batch(Batch $b): array
    {
        return $b->only(['id', 'number', 'insurer_id', 'branch_id', 'guide_type', 'competence', 'status', 'guides_count', 'total_cents', 'paid_cents', 'glosa_cents', 'receivable_id', 'xml_hash', 'protocol'])
            + ['closed_at' => $b->closed_at?->toIso8601String()];
    }

    private function assertBranch(string $branchId): void
    {
        $allowed = $this->context->allowedBranchIds();
        abort_unless($allowed === null || in_array($branchId, $allowed, true), 404);
    }
}
