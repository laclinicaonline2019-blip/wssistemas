<?php

namespace App\Modules\Payments\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Models\PaymentGateway;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/** Configuração dos gateways de pagamento da clínica (integracao.gerenciar). */
class GatewayWebController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): View
    {
        $gateways = PaymentGateway::query()->orderByDesc('is_default')->orderBy('created_at')->get();

        return view('payments.gateways', [
            'gateways' => $gateways,
            'events' => PaymentWebhookEvent::query()->where('company_id', $request->user()->company_id)->latest('received_at')->limit(30)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, true);
        $this->payments->saveGateway($request->user(), $data);

        return back()->with('success', 'Gateway configurado. Copie a URL de webhook e cadastre no painel do gateway.');
    }

    public function update(Request $request, PaymentGateway $gateway): RedirectResponse
    {
        $this->payments->saveGateway($request->user(), $this->validated($request, false), $gateway);

        return back()->with('success', 'Gateway atualizado.');
    }

    public function test(PaymentGateway $gateway): RedirectResponse
    {
        try {
            return back()->with('success', $this->payments->provider($gateway)->testConnection($gateway));
        } catch (Throwable $e) {
            return back()->with('error', 'Falha no teste: '.$e->getMessage());
        }
    }

    public function rotate(PaymentGateway $gateway): RedirectResponse
    {
        $this->payments->rotateWebhookToken($gateway);

        return back()->with('success', 'Novo token de webhook gerado — atualize-o no painel do gateway.');
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'provider' => [$creating ? 'required' : 'nullable', Rule::in(array_keys(PaymentGateway::PROVIDERS))],
            'mode' => ['nullable', Rule::in(['sandbox', 'production'])],
            'name' => ['nullable', 'string', 'max:80'],
            'credentials' => ['nullable', 'array'],
            'credentials.api_key' => ['nullable', 'string', 'max:300'],
            'credentials.client_id' => ['nullable', 'string', 'max:120'],
            'credentials.client_secret' => ['nullable', 'string', 'max:200'],
            'settings' => ['nullable', 'array'],
            'settings.max_installments' => ['nullable', 'integer', 'between:1,12'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
        ]);
    }
}
