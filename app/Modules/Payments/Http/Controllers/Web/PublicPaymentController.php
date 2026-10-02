<?php

namespace App\Modules\Payments\Http\Controllers\Web;

use App\Core\Support\BusinessRuleViolation;
use App\Core\Support\Format;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Documents\Services\DocumentService;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Patients\Models\Patient;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentGateway;
use App\Modules\Payments\Providers\CieloApiProvider;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Platform\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Página pública da cobrança (link enviado ao paciente): valor, PIX copia-e-cola/QR,
 * botão para a página do gateway ou — na Cielo API com split — formulário de cartão
 * tokenizado no navegador (os dados do cartão nunca chegam a este servidor).
 */
class PublicPaymentController extends Controller
{
    /** Resultados exibidos ao paciente (mensagens fixas — nada vindo da URL é exibido literalmente). */
    private const RESULTS = [
        'ok' => ['success', 'Pagamento aprovado. Obrigado!'],
        'denied' => ['error', 'Cartão não aprovado pela operadora. Confira os dados ou use outro cartão.'],
        'busy' => ['info', 'Seu pagamento está em processamento. Aguarde alguns instantes e atualize a página.'],
        'error' => ['error', 'Não foi possível processar o pagamento agora. Tente novamente em alguns minutos.'],
    ];

    public function __invoke(Request $request, string $token, TenantContext $context, DocumentService $documents): View
    {
        $data = $this->load($token, $context);
        $charge = $data['charge'];
        $qr = $charge->pix_qr_image ? 'data:image/png;base64,'.$charge->pix_qr_image : ($charge->pix_payload ? $documents->qrDataUri($charge->pix_payload) : null);

        return view('payments.public', $data + [
            'qr' => $qr,
            'cardForm' => $charge->provider === 'cielo_api' && $charge->isOpen() && ! $charge->provider_charge_id,
            'brands' => CieloApiProvider::BRANDS,
            'maxInstallments' => max(1, (int) ($data['gatewaySettings']['max_installments'] ?? 1)),
            'result' => self::RESULTS[$request->query('r')] ?? null,
        ]);
    }

    /** Sessão de tokenização (AccessToken do Silent Order Post), pedida pelo navegador. */
    public function cardSession(string $token, TenantContext $context, PaymentService $payments): JsonResponse
    {
        $charge = $this->load($token, $context)['charge'];

        try {
            return response()->json($context->runFor($charge->company_id, fn () => $payments->tokenizationConfig(PaymentCharge::query()->findOrFail($charge->id))));
        } catch (Throwable $e) {
            Log::warning('Falha ao iniciar tokenização de cartão', ['charge' => $charge->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Pagamento com cartão indisponível no momento.'], 503);
        }
    }

    /** Recebe SOMENTE o PaymentToken (e bandeira/parcelas/nome) — nunca dados do cartão. */
    public function payCard(Request $request, string $token, TenantContext $context, PaymentService $payments): RedirectResponse
    {
        $charge = $this->load($token, $context)['charge'];
        // Rota pública sem sessão: dados inválidos voltam com código de resultado (sem "flash").
        $validator = Validator::make($request->only(['payment_token', 'brand', 'installments', 'holder_name']), [
            'payment_token' => ['required', 'uuid'],
            'brand' => ['required', Rule::in(array_keys(CieloApiProvider::BRANDS))],
            'installments' => ['required', 'integer', 'between:1,12'],
            'holder_name' => ['required', 'string', 'max:60'],
        ]);
        if ($validator->fails()) {
            return redirect()->route('payments.public', [$token, 'r' => 'error']);
        }
        $data = $validator->validated();

        try {
            $result = $context->runFor($charge->company_id, fn () => $payments->payWithCard(
                PaymentCharge::query()->findOrFail($charge->id), $data['payment_token'], $data['brand'], (int) $data['installments'], $data['holder_name'],
            ));
            $r = $result->status === 'paid' ? 'ok' : 'busy';
        } catch (BusinessRuleViolation $e) {
            $r = match ($e->errorCode) {
                'card_denied' => 'denied',
                'payment_in_progress', 'already_processed' => 'busy',
                default => 'error',
            };
        } catch (Throwable $e) {
            Log::error('Falha no pagamento com cartão', ['charge' => $charge->id, 'error' => $e->getMessage()]);
            $r = 'error';
        }

        return redirect()->route('payments.public', [$token, 'r' => $r]);
    }

    private function load(string $token, TenantContext $context): array
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1, 404);

        return $context->runAsSystem(function () use ($token) {
            $charge = PaymentCharge::query()->withoutGlobalScopes()->where('public_token', $token)->first();
            abort_unless($charge, 404);
            $receivable = Receivable::query()->withoutGlobalScopes()->find($charge->receivable_id);
            $patient = $charge->patient_id ? Patient::query()->withoutGlobalScopes()->find($charge->patient_id) : null;

            return [
                'charge' => $charge, 'description' => $receivable?->description, 'company' => Company::query()->find($charge->company_id),
                'patient' => $patient ? Format::initials($patient->displayName()) : null,
                'gatewaySettings' => PaymentGateway::query()->withoutGlobalScopes()->find($charge->gateway_id)?->settings ?? [],
            ];
        });
    }
}
