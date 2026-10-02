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

    @if ($result)
        <div class="alert alert-{{ $result[0] }} mt-2" role="status">{{ $result[1] }}</div>
    @endif

    @if ($cardForm)
        {{-- Cartão: os campos SEM "name" não são enviados ao servidor; o script da Cielo (Silent Order Post)
             envia os dados direto para a Cielo e devolve só o PaymentToken, que é o único dado postado aqui. --}}
        <form method="post" action="{{ route('payments.card_pay', $charge->public_token) }}" class="card mt-2" data-sop-form data-session-url="{{ route('payments.card_session', $charge->public_token) }}">
            <div class="card__body stack">
                <strong>Pagar com cartão de crédito</strong>
                <input type="hidden" name="payment_token" data-sop-token>
                <input type="hidden" class="bp-sop-cardtype" value="creditCard">
                <div class="field"><label for="cc-holder">Nome impresso no cartão</label>
                    <input id="cc-holder" name="holder_name" class="input bp-sop-cardholdername" maxlength="60" autocomplete="cc-name" required></div>
                <div class="field"><label for="cc-number">Número do cartão</label>
                    <input id="cc-number" class="input bp-sop-cardnumber" inputmode="numeric" autocomplete="cc-number" maxlength="19" required></div>
                <div class="row">
                    <div class="field grow"><label for="cc-exp">Validade (MM/AAAA)</label>
                        <input id="cc-exp" class="input bp-sop-cardexpirationdate" placeholder="MM/AAAA" inputmode="numeric" autocomplete="cc-exp" maxlength="7" required></div>
                    <div class="field grow"><label for="cc-cvv">CVV</label>
                        <input id="cc-cvv" class="input bp-sop-cardcvvc" inputmode="numeric" autocomplete="cc-csc" maxlength="4" required></div>
                </div>
                <div class="row">
                    <div class="field grow"><label for="cc-brand">Bandeira</label>
                        <select id="cc-brand" name="brand" class="input" required>@foreach ($brands as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div class="field grow"><label for="cc-inst">Parcelas</label>
                        <select id="cc-inst" name="installments" class="input">@for ($i = 1; $i <= $maxInstallments; $i++)<option value="{{ $i }}">{{ $i }}x de {{ Format::money(intdiv($charge->amount_cents, $i)) }}{{ $i === 1 ? ' (à vista)' : ' sem juros' }}</option>@endfor</select></div>
                </div>
                <div class="field-error" data-sop-error hidden></div>
                <button class="btn btn-brand btn-block" type="submit">Pagar {{ Format::money($charge->amount_cents) }}</button>
                <p class="small muted">Os dados do cartão são enviados diretamente à Cielo, com criptografia. A clínica não recebe nem armazena o número do cartão.</p>
            </div>
        </form>
    @endif

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
