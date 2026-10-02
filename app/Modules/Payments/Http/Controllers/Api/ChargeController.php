<?php

namespace App\Modules\Payments\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Http\ParsesMoney;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentGateway;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** API de cobranças online. Idempotência pelo header "Idempotency-Key" (ou campo idempotency_key). */
class ChargeController extends Controller
{
    use ParsesMoney;

    public function __construct(private readonly PaymentService $payments) {}

    public function store(Request $request, Receivable $receivable): JsonResponse
    {
        $data = $request->validate([
            'gateway_id' => ['nullable', 'string', 'size:26'],
            'billing_type' => ['required', Rule::in(array_keys(PaymentCharge::BILLING_TYPES))],
            'due_date' => ['required', 'date'],
            'idempotency_key' => ['nullable', 'string', 'min:16', 'max:64'],
        ]);
        $key = $request->header('Idempotency-Key') ?? $data['idempotency_key'] ?? null;
        abort_if(! $key || strlen($key) < 16 || strlen($key) > 64, 422, 'Envie o header Idempotency-Key (16 a 64 caracteres).');

        $gateway = isset($data['gateway_id']) ? PaymentGateway::query()->findOrFail($data['gateway_id']) : ($this->payments->defaultGateway() ?? abort(422, 'Nenhum gateway configurado.'));
        $charge = $this->payments->createCharge($request->user(), $receivable, $gateway, $data['billing_type'],
            $this->cents($request, 'amount_cents'), $data['due_date'], $key);

        return response()->json(['data' => $this->charge($charge)], $charge->wasRecentlyCreated ? 201 : 200);
    }

    public function show(PaymentCharge $charge): JsonResponse
    {
        return response()->json(['data' => $this->charge($charge)]);
    }

    public function sync(PaymentCharge $charge): JsonResponse
    {
        return response()->json(['data' => $this->charge($this->payments->sync($charge))]);
    }

    public function cancel(Request $request, PaymentCharge $charge): JsonResponse
    {
        return response()->json(['data' => $this->charge($this->payments->cancelCharge($request->user(), $charge))]);
    }

    public function refund(Request $request, PaymentCharge $charge): JsonResponse
    {
        return response()->json(['data' => $this->charge($this->payments->refundCharge($request->user(), $charge))]);
    }

    private function charge(PaymentCharge $c): array
    {
        return $c->only(['id', 'receivable_id', 'gateway_id', 'provider', 'mode', 'amount_cents', 'billing_type', 'status', 'provider_charge_id',
            'payment_url', 'pix_payload', 'paid_cents', 'net_cents', 'transaction_id', 'review_reason'])
            + ['due_date' => $c->due_date->toDateString(), 'paid_at' => $c->paid_at?->toIso8601String(), 'public_url' => $c->publicUrl(), 'test_mode' => $c->modeBadge()];
    }
}
