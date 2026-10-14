<?php

namespace App\Modules\Sprint\Http\Controllers\Web;

use App\Core\Support\Format;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Payments\Models\PaymentGateway;
use App\Modules\Sprint\Models\SprintSetting;
use App\Modules\Sprint\Services\CommissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Configuração do Melhor Sprint: gateways preferidos (PIX/cartão) e NFS-e opcional. */
class SprintSettingsController extends Controller
{
    public function edit(CommissionService $commission, TenantContext $context): View
    {
        return view('sprint.settings', [
            'settings' => SprintSetting::current(),
            'gateways' => PaymentGateway::query()->where('is_active', true)->orderBy('name')->get(['id', 'provider', 'mode', 'name']),
            'commissionBp' => $commission->rateFor($context->companyId()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $ids = PaymentGateway::query()->pluck('provider', 'id');
        $data = $request->validate([
            'pix_gateway_id' => ['nullable', Rule::in($ids->filter(fn ($p) => in_array($p, ['asaas', 'mock'], true))->keys()->all())],
            'card_gateway_id' => ['nullable', Rule::in($ids->filter(fn ($p) => $p === 'cielo_api')->keys()->all())],
            'nfse_enabled' => ['nullable', 'boolean'],
            'nfse_auto' => ['nullable', 'boolean'],
            'nfse_gateway_id' => ['nullable', Rule::in($ids->filter(fn ($p) => in_array($p, ['asaas', 'mock'], true))->keys()->all())],
            'nfse_service_code' => ['nullable', 'string', 'max:30'],
            'nfse_service_name' => ['nullable', 'string', 'max:255'],
            'nfse_iss' => ['nullable', 'string', 'max:10'],
            'nfse_description' => ['nullable', 'string', 'max:500'],
        ], [], ['nfse_gateway_id' => 'emissor da NFS-e', 'nfse_iss' => 'alíquota de ISS']);

        $iss = isset($data['nfse_iss']) && $data['nfse_iss'] !== '' ? Format::parseMoney($data['nfse_iss']) : 0;
        if ($iss === null || $iss < 0 || $iss > 1000) {
            throw ValidationException::withMessages(['nfse_iss' => 'Alíquota de ISS entre 0% e 10%.']);
        }
        if (! empty($data['nfse_enabled']) && empty($data['nfse_gateway_id'])) {
            throw ValidationException::withMessages(['nfse_gateway_id' => 'Escolha a conta ASAAS que emite as notas.']);
        }

        $settings = SprintSetting::current();
        $settings->fill([
            'pix_gateway_id' => $data['pix_gateway_id'] ?? null, 'card_gateway_id' => $data['card_gateway_id'] ?? null,
            'nfse_enabled' => (bool) ($data['nfse_enabled'] ?? false), 'nfse_auto' => (bool) ($data['nfse_auto'] ?? false),
            'nfse_gateway_id' => $data['nfse_gateway_id'] ?? null, 'nfse_service_code' => $data['nfse_service_code'] ?? null,
            'nfse_service_name' => $data['nfse_service_name'] ?? null, 'nfse_iss_bp' => $iss, 'nfse_description' => $data['nfse_description'] ?? null,
        ])->save();

        return back()->with('success', 'Configurações do Melhor Sprint salvas.');
    }
}
