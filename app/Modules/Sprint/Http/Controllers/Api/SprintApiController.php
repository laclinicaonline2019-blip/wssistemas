<?php

namespace App\Modules\Sprint\Http\Controllers\Api;

use App\Core\Tenancy\TenantContext;
use App\Core\Validation\ExistsInTenant;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Http\ParsesMoney;
use App\Modules\Patients\Models\Patient;
use App\Modules\Sprint\Models\SprintPayment;
use App\Modules\Sprint\Models\SprintProfessional;
use App\Modules\Sprint\Models\SprintSale;
use App\Modules\Sprint\Services\NfseService;
use App\Modules\Sprint\Services\SprintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * API do Melhor Sprint (v1): vendas com divisão, pagamentos (ASAAS/Cielo/maquininha/dinheiro),
 * situação para confirmação em tela e NFS-e. Valores sempre em centavos; idempotência por
 * "Idempotency-Key" ao registrar pagamentos.
 */
class SprintApiController extends Controller
{
    use ParsesMoney;

    public function __construct(private readonly SprintService $sprint, private readonly NfseService $nfse, private readonly TenantContext $context) {}

    public function professionals(): JsonResponse
    {
        return response()->json(['data' => SprintProfessional::query()->where('is_active', true)->orderBy('name')->get()
            ->map(fn (SprintProfessional $p) => ['id' => $p->id, 'name' => $p->name, 'kind' => $p->kind, 'split_type' => $p->split_type,
                'split_value' => $p->split_value, 'split_label' => $p->splitLabel(), 'asaas_split' => (bool) $p->asaas_wallet_id,
                'cielo_split' => (bool) $p->cielo_subordinate_id])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'string', 'size:26'],
            'patient_id' => ['nullable', 'string', 'size:26', new ExistsInTenant(Patient::class)],
            'customer_name' => ['nullable', 'string', 'max:150'],
            'customer_document' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:200'],
            'issue_nfse' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.professional_id' => ['nullable', 'string', 'size:26'],
            'items.*.description' => ['required', 'string', 'max:200'],
            'items.*.amount_cents' => ['required', 'integer', 'min:1', 'max:9999999999'],
            'items.*.split_type' => ['nullable', Rule::in(['percent', 'fixed'])],
            'items.*.split_value' => ['nullable', 'integer', 'min:0'],
        ]);

        $sale = $this->sprint->createSale($request->user(), $data);

        return response()->json(['data' => $this->sale($sale)], 201);
    }

    public function show(SprintSale $sale): JsonResponse
    {
        $this->authorizeBranch($sale);

        return response()->json(['data' => $this->sale($this->sprint->refresh($sale))]);
    }

    public function pay(Request $request, SprintSale $sale): JsonResponse
    {
        $this->authorizeBranch($sale);
        $data = $request->validate([
            'method' => ['required', Rule::in(array_keys(SprintPayment::METHODS))],
            'channel' => ['required', Rule::in(array_keys(SprintPayment::CHANNELS))],
            'card_brand' => ['nullable', 'string', 'max:30'],
            'installments' => ['nullable', 'integer', 'min:1', 'max:24'],
            'nsu' => ['nullable', 'string', 'max:60'],
            'terminal_split' => ['nullable', 'boolean'],
            'cash_given_cents' => ['nullable', 'integer', 'min:0'],
            'gateway_id' => ['nullable', 'string', 'size:26'],
            'idempotency_key' => ['nullable', 'string', 'min:16', 'max:64'],
        ]);
        $data['idempotency_key'] = $request->header('Idempotency-Key') ?? $data['idempotency_key'] ?? null;
        abort_if(! $data['idempotency_key'] || strlen($data['idempotency_key']) < 16 || strlen($data['idempotency_key']) > 64, 422, 'Envie o header Idempotency-Key (16 a 64 caracteres).');
        $data['amount_cents'] = $this->cents($request, 'amount_cents');

        $payment = $this->sprint->addPayment($request->user(), $sale, array_filter($data, fn ($v) => $v !== null));

        return response()->json(['data' => $this->payment($payment->fresh('charge')), 'sale' => $this->sale($sale->fresh())], $payment->wasRecentlyCreated ? 201 : 200);
    }

    public function nfse(Request $request, SprintSale $sale): JsonResponse
    {
        $this->authorizeBranch($sale);
        $invoice = $this->nfse->issue($request->user(), $sale);

        return response()->json(['data' => ['id' => $invoice->id, 'status' => $invoice->status, 'value_cents' => $invoice->value_cents,
            'number' => $invoice->number, 'pdf_url' => $invoice->pdf_url, 'test_mode' => $invoice->modeBadge()]], 201);
    }

    private function sale(SprintSale $sale): array
    {
        $sale->load(['items', 'payments.charge', 'invoices', 'splits' => fn ($q) => $q->where('status', '!=', 'reversed')]);

        return [
            'id' => $sale->id, 'code' => $sale->code(), 'status' => $sale->status, 'customer' => $sale->customerLabel(),
            'total_cents' => $sale->total_cents, 'professional_cents' => $sale->professional_cents, 'house_cents' => $sale->house_cents,
            'commission_bp' => $sale->commission_bp,
            'paid_cents' => (int) $sale->payments->where('status', 'paid')->sum('amount_cents'),
            'items' => $sale->items->map(fn ($i) => ['description' => $i->description, 'professional_id' => $i->professional_id, 'amount_cents' => $i->amount_cents,
                'split_type' => $i->split_type, 'split_value' => $i->split_value, 'professional_cents' => $i->professional_cents, 'house_cents' => $i->house_cents])->values(),
            'payments' => $sale->payments->map(fn ($p) => $this->payment($p))->values(),
            'division' => $sale->splits->groupBy('party')->map(fn ($g) => (int) $g->sum('amount_cents')),
            'nfse' => $sale->invoices->map(fn ($n) => ['id' => $n->id, 'status' => $n->status, 'value_cents' => $n->value_cents, 'number' => $n->number, 'pdf_url' => $n->pdf_url])->values(),
        ];
    }

    private function payment(SprintPayment $p): array
    {
        $c = $p->charge;

        return [
            'id' => $p->id, 'method' => $p->method, 'channel' => $p->channel, 'amount_cents' => $p->amount_cents, 'status' => $p->status,
            'change_cents' => $p->change_cents, 'allocation' => $p->allocation,
            'charge' => $c ? ['id' => $c->id, 'status' => $c->status, 'pix_payload' => $c->pix_payload, 'payment_url' => $c->payment_url,
                'card_page_url' => $c->provider === 'cielo_api' ? $c->publicUrl() : null, 'test_mode' => $c->modeBadge()] : null,
        ];
    }

    private function authorizeBranch(SprintSale $sale): void
    {
        $allowed = $this->context->allowedBranchIds();
        abort_if($allowed !== null && ! in_array($sale->branch_id, $allowed, true), 404);
    }
}
