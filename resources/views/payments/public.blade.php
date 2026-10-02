@extends('layouts.guest', ['title' => 'Pagamento'])

@php use App\Core\Support\Format; use App\Modules\Payments\Models\PaymentCharge; @endphp

@section('content')
    <h1>Pagamento</h1>
    <p class="muted">{{ $company?->trade_name }}</p>

    @if ($charge->modeBadge())
        <div class="alert alert-warning mt-1"><strong>{{ $charge->modeBadge() }}</strong> — ambiente de {{ $charge->modeBadge() === 'MOCK' ? 'simulação' : 'testes' }}: nenhum valor real será cobrado.</div>
    @endif

    <div class="card mt-2"><div class="card__body">
        <dl class="dl">
            <dt>Valor</dt><dd><strong class="kpi__value">{{ Format::money($charge->amount_cents) }}</strong></dd>
            <dt>Referente a</dt><dd>{{ $description }}</dd>
            @if ($patient)<dt>Paciente</dt><dd>{{ $patient }}</dd>@endif
            <dt>Vencimento</dt><dd>{{ $charge->due_date->format('d/m/Y') }}</dd>
            <dt>Situação</dt><dd>{{ PaymentCharge::STATUSES[$charge->status] }}</dd>
        </dl>
    </div></div>

    @if ($charge->status === 'paid')
        <div class="alert alert-success mt-2">Pagamento confirmado. Obrigado!</div>
    @elseif ($charge->isOpen())
        @if ($charge->pix_payload)
            <div class="card mt-2"><div class="card__body stack">
                <strong>Pague com PIX</strong>
                @if ($qr)<img src="{{ $qr }}" alt="QR Code PIX" width="220" height="220" class="pix-qr">@endif
                <label class="label" for="pix">PIX copia e cola</label>
                <textarea id="pix" class="input" rows="3" readonly>{{ $charge->pix_payload }}</textarea>
                <button type="button" class="btn" data-copy="#pix">Copiar código PIX</button>
            </div></div>
        @endif
        @if ($charge->payment_url)
            <a class="btn btn-brand btn-block mt-2" href="{{ $charge->payment_url }}" rel="noopener">Pagar {{ $charge->billing_type === 'pix' ? 'na página segura do gateway' : 'com cartão, boleto ou PIX' }}</a>
        @endif
        <p class="small muted mt-2">A confirmação é automática após o processamento pelo banco/gateway. Esta página não solicita dados de cartão — o pagamento com cartão acontece na página segura do gateway.</p>
    @else
        <div class="alert alert-info mt-2">Esta cobrança não está mais disponível para pagamento. Fale com a clínica.</div>
    @endif
@endsection
