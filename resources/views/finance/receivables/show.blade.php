@extends('layouts.app', ['title' => 'Conta a receber'])

@php
    use App\Core\Support\Format;
    $me = auth()->user();
    $open = in_array($r->status, ['open', 'partial'], true);
    $canDiscount = $me->hasPermission('financeiro.editar', $r->branch_id) || $me->hasPermission('caixa.conferir', $r->branch_id);
@endphp

@section('content')
@include('finance._tabs')
<div class="page-head">
    <div>
        <h1>{{ $r->patient?->displayName() ?? $r->description }}</h1>
        <p>{{ $r->description }} · {{ $r->category->name }} · {{ $r->branch->name }} · vencimento {{ $r->due_date->format('d/m/Y') }}
            <span class="badge {{ ['paid' => 'badge-success', 'partial' => 'badge-info', 'cancelled' => ''][$r->status] ?? ($r->isOverdue() ? 'badge-danger' : 'badge-warning') }}">{{ $r->statusLabel() }}</span></p>
    </div>
    <div class="row">
        @if ($r->patient)<a class="btn" href="{{ route('patients.show', $r->patient) }}">Ficha do paciente</a>@endif
        <a class="btn" href="{{ route('receivables.index') }}">Voltar</a>
    </div>
</div>

@if (session('print_receipt'))
    <iframe class="print-frame" src="{{ route('transactions.receipt', session('print_receipt')) }}" title="Impressão do recibo"></iframe>
    <div class="alert alert-info">Imprimindo o recibo… <a href="{{ route('transactions.receipt', session('print_receipt')) }}" target="_blank" rel="noopener">abrir recibo</a> ·
        <a href="{{ route('transactions.receipt', [session('print_receipt'), 'format' => 'a4']) }}" target="_blank" rel="noopener">recibo A4</a></div>
@endif

<div class="grid grid-4">
    <div class="card kpi"><div class="kpi__label">Valor</div><div class="kpi__value">{{ Format::money($r->amount_cents) }}</div><div class="kpi__hint">&nbsp;</div></div>
    <div class="card kpi"><div class="kpi__label">Desconto</div><div class="kpi__value">{{ Format::money($r->discount_cents) }}</div><div class="kpi__hint">&nbsp;</div></div>
    <div class="card kpi"><div class="kpi__label">Recebido</div><div class="kpi__value kpi-pos">{{ Format::money($r->paid_cents) }}</div><div class="kpi__hint">&nbsp;</div></div>
    <div class="card kpi"><div class="kpi__label">Saldo</div><div class="kpi__value">{{ Format::money($r->balanceCents()) }}</div><div class="kpi__hint">&nbsp;</div></div>
</div>

<div class="grid grid-2 mt-2">
    @if ($open && ($me->hasPermission('caixa.operar') || $me->hasPermission('financeiro.editar')))
        <section class="card">
            <div class="card__head"><h2>Receber</h2>@if ($openSession)<span class="badge badge-success">caixa aberto</span>@else<span class="badge">sem caixa aberto</span>@endif</div>
            <form method="post" action="{{ route('receivables.receive', $r) }}" class="card__body form-grid" data-receive-form data-balance="{{ $r->balanceCents() }}">
                @csrf
                <div class="field col-6"><label for="rv-method">Forma de pagamento</label>
                    <select id="rv-method" name="method" class="input" data-method>@foreach ($methods as $k => $l)<option value="{{ $k }}" @selected(old('method', $openSession ? 'cash' : 'pix') === $k)>{{ $l }}</option>@endforeach</select></div>
                <x-field name="amount" label="Valor recebido (R$)" col="col-6" mask="money" inputmode="numeric" :value="old('amount', number_format($r->balanceCents() / 100, 2, ',', '.'))" />
                <div class="col-12 form-grid" data-card-fields>
                    <x-field name="card_installments" label="Parcelas" type="number" min="1" max="24" col="col-3" :value="old('card_installments', 1)" />
                    <x-field name="card_brand" label="Bandeira" col="col-4" maxlength="30" placeholder="Visa, Master…" />
                    <x-field name="authorization_code" label="NSU / autorização / ID PIX" col="col-5" maxlength="60" />
                </div>
                <div class="col-12 form-grid" data-cash-fields>
                    <div class="field col-6"><label for="rv-given">Valor entregue pelo paciente</label><input id="rv-given" class="input money-input" data-mask="money" inputmode="numeric" data-given></div>
                    <div class="field col-6"><span class="label">Troco</span><div class="kpi__value" data-change>—</div></div>
                    @unless ($openSession)<p class="help col-12 text-danger">Dinheiro exige caixa aberto: <a href="{{ route('cash.index') }}">abrir meu caixa</a>.</p>@endunless
                </div>
                @if ($canDiscount)
                    <x-field name="discount" label="Desconto (R$)" col="col-6" mask="money" inputmode="numeric" help="Requer permissão; fica registrado na auditoria." />
                @endif
                @unless ($openSession)
                    <x-field name="paid_on" label="Data do pagamento" type="date" col="col-6" :value="now('America/Sao_Paulo')->toDateString()" help="Lançamentos fora do caixa (ex.: transferência) podem ter data de até 60 dias atrás." />
                @endunless
                <div class="col-12 form-actions"><button class="btn btn-primary" type="submit">Registrar recebimento e imprimir recibo</button></div>
            </form>
        </section>
    @endif

    <section class="card">
        <div class="card__head"><h2>Detalhes</h2></div>
        <div class="card__body">
            <dl class="dl">
                <dt>Origem</dt><dd>{{ ['appointment' => 'Agendamento', 'manual' => 'Lançamento manual', 'gateway' => 'Cobrança online'][$r->origin] ?? $r->origin }}
                    @if ($r->appointment) · <a href="{{ route('agenda.show', $r->appointment) }}">{{ $r->appointment->protocol }}</a>@endif</dd>
                @if ($r->doctor)<dt>Médico</dt><dd>{{ $r->doctor->displayName() }}</dd>@endif
                @if ($r->notes)<dt>Observações</dt><dd>{{ $r->notes }}</dd>@endif
                @if ($r->status === 'cancelled')<dt>Cancelada</dt><dd>{{ $r->cancelled_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }} — {{ $r->cancel_reason }}</dd>@endif
            </dl>
            @if ($open && $r->paid_cents === 0 && $me->hasPermission('financeiro.editar'))
                <form method="post" action="{{ route('receivables.cancel', $r) }}" class="row mt-2" data-confirm="Cancelar esta conta?">
                    @csrf
                    <label class="sr-only" for="rc">Motivo</label><input id="rc" name="reason" class="input" minlength="5" maxlength="255" required placeholder="Motivo do cancelamento">
                    <button class="btn btn-danger" type="submit">Cancelar conta</button>
                </form>
            @endif
        </div>
    </section>
</div>

<section class="card mt-2">
    <div class="card__head"><h2>Movimentações</h2></div>
    @include('finance._transactions', ['transactions' => $r->transactions])
</section>
@endsection
