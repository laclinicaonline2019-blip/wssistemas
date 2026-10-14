@extends('layouts.app', ['title' => 'Configurações · Melhor Sprint'])

@php
    use App\Modules\Payments\Models\PaymentGateway;
    $s = $settings;
    $label = fn ($g) => $g->name.($g->modeBadge() ? ' ('.$g->modeBadge().')' : '');
@endphp

@section('content')
@include('sprint._head', ['subtitle' => 'Gateways de pagamento e nota fiscal'])

<form method="post" action="{{ route('sprint.settings.update') }}" class="grid grid-2">
    @csrf @method('put')
    <section class="card">
        <div class="card__head"><h2>Pagamentos</h2></div>
        <div class="card__body form-grid">
            <div class="field col-12"><label for="s-pix">PIX e link de pagamento (ASAAS)</label>
                <select id="s-pix" name="pix_gateway_id" class="input"><option value="">Automático</option>
                    @foreach ($gateways->whereIn('provider', ['asaas', 'mock']) as $g)<option value="{{ $g->id }}" @selected($s->pix_gateway_id === $g->id)>{{ $label($g) }}</option>@endforeach</select></div>
            <div class="field col-12"><label for="s-card">Cartão digitado (Cielo API E-commerce com split)</label>
                <select id="s-card" name="card_gateway_id" class="input"><option value="">Automático</option>
                    @foreach ($gateways->where('provider', 'cielo_api') as $g)<option value="{{ $g->id }}" @selected($s->card_gateway_id === $g->id)>{{ $label($g) }}</option>@endforeach</select></div>
            <p class="help col-12">As credenciais ficam em <a href="{{ route('gateways.index') }}">Administração → Pagamentos online</a>. Débito e crédito na maquininha (Cielo ou outra) são registrados com o NSU do comprovante.</p>
            <div class="col-12 alert alert-info small">Comissão do Melhor Sprint nesta clínica: <strong>{{ number_format($commissionBp / 100, 2, ',', '.') }}%</strong> sobre cada pagamento, descontada da parte da clínica
                (separada pelo próprio gateway quando possível; nas vendas em dinheiro/maquininha é cobrada à parte).</div>
        </div>
    </section>

    <section class="card">
        <div class="card__head"><h2>NFS-e (opcional)</h2></div>
        <div class="card__body form-grid">
            <label class="check col-12"><input type="hidden" name="nfse_enabled" value="0"><input type="checkbox" name="nfse_enabled" value="1" @checked(old('nfse_enabled', $s->nfse_enabled))>
                <span><strong>Emitir NFS-e pelo sistema</strong> — sobre o valor que ficou para a clínica (total − partes dos profissionais).</span></label>
            <label class="check col-12"><input type="hidden" name="nfse_auto" value="0"><input type="checkbox" name="nfse_auto" value="1" @checked(old('nfse_auto', $s->nfse_auto))>
                <span>Emitir automaticamente quando a venda for quitada (se marcada na venda).</span></label>
            <div class="field col-12"><label for="s-nfg">Emissor (conta ASAAS com notas fiscais habilitadas)</label>
                <select id="s-nfg" name="nfse_gateway_id" class="input"><option value="">—</option>
                    @foreach ($gateways->whereIn('provider', ['asaas', 'mock']) as $g)<option value="{{ $g->id }}" @selected(old('nfse_gateway_id', $s->nfse_gateway_id) === $g->id)>{{ $label($g) }}</option>@endforeach</select>
                @error('nfse_gateway_id')<div class="field-error">{{ $message }}</div>@enderror</div>
            <x-field name="nfse_service_code" label="Código do serviço municipal" col="col-6" maxlength="30" :value="$s->nfse_service_code" placeholder="04.03" />
            <x-field name="nfse_iss" label="Alíquota de ISS (%)" col="col-6" maxlength="10" :value="number_format($s->nfse_iss_bp / 100, 2, ',', '.')" />
            <x-field name="nfse_service_name" label="Descrição do serviço municipal" col="col-12" maxlength="255" :value="$s->nfse_service_name" placeholder="Hospitais, clínicas, laboratórios…" />
            <x-field name="nfse_description" label="Texto da nota" col="col-12" maxlength="500" :value="$s->nfse_description" placeholder="Serviços prestados pela clínica" />
            <p class="help col-12">No ASAAS, ative "Notas fiscais" (certificado digital A1, inscrição municipal e serviços da prefeitura). A nota exige paciente com CPF na venda. Em SANDBOX/MOCK as notas não têm valor fiscal.</p>
        </div>
    </section>
    <div class="col-12 form-actions span-2"><button class="btn btn-sprint" type="submit">Salvar configurações</button></div>
</form>
@endsection
