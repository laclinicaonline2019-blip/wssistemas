@extends('layouts.app', ['title' => 'Conta a pagar'])

@php use App\Core\Support\Format; $me = auth()->user(); $open = in_array($p->status, ['open', 'partial'], true); @endphp

@section('content')
@include('finance._tabs')
<div class="page-head">
    <div><h1>{{ $p->supplier }}</h1>
        <p>{{ $p->description }} · {{ $p->category->name }}{{ $p->branch ? ' · '.$p->branch->name : '' }} · vencimento {{ $p->due_date->format('d/m/Y') }}
            <span class="badge {{ ['paid' => 'badge-success', 'partial' => 'badge-info', 'cancelled' => ''][$p->status] ?? ($p->isOverdue() ? 'badge-danger' : 'badge-warning') }}">{{ $p->statusLabel() }}</span></p></div>
    <a class="btn" href="{{ route('payables.index') }}">Voltar</a>
</div>

<div class="grid grid-3">
    <div class="card kpi"><div class="kpi__label">Valor</div><div class="kpi__value">{{ Format::money($p->amount_cents) }}</div><div class="kpi__hint">{{ $p->installments > 1 ? "parcela {$p->installment} de {$p->installments}" : ' ' }}</div></div>
    <div class="card kpi"><div class="kpi__label">Pago</div><div class="kpi__value">{{ Format::money($p->paid_cents) }}</div><div class="kpi__hint">&nbsp;</div></div>
    <div class="card kpi"><div class="kpi__label">Saldo</div><div class="kpi__value">{{ Format::money($p->balanceCents()) }}</div><div class="kpi__hint">&nbsp;</div></div>
</div>

@if ($open && $me->hasPermission('financeiro.editar'))
<div class="grid grid-2 mt-2">
    <section class="card">
        <div class="card__head"><h2>Registrar pagamento</h2></div>
        <form method="post" action="{{ route('payables.pay', $p) }}" class="card__body form-grid">
            @csrf
            <div class="field col-6"><label for="pp-m">Forma</label>
                <select id="pp-m" name="method" class="input">@foreach ($methods as $k => $l)<option value="{{ $k }}" @selected(old('method', 'bank_transfer') === $k)>{{ $l }}</option>@endforeach</select></div>
            <x-field name="amount" label="Valor (R$)" col="col-6" mask="money" inputmode="numeric" :value="number_format($p->balanceCents() / 100, 2, ',', '.')" />
            <x-field name="paid_on" label="Data do pagamento" type="date" col="col-6" :value="now('America/Sao_Paulo')->toDateString()" />
            <x-field name="authorization_code" label="Comprovante / autenticação" col="col-6" maxlength="60" />
            <p class="help col-12">Pagamento em dinheiro sai do seu caixa aberto{{ $openSession ? '' : ' (abra o caixa antes)' }}.</p>
            <div class="col-12 form-actions"><button class="btn btn-primary" type="submit">Registrar pagamento</button></div>
        </form>
    </section>
    @if ($p->paid_cents === 0)
    <section class="card danger-zone">
        <div class="card__head"><h2>Cancelar conta</h2></div>
        <form method="post" action="{{ route('payables.cancel', $p) }}" class="card__body stack" data-confirm="Cancelar esta conta?">
            @csrf
            <input name="reason" class="input" minlength="5" maxlength="255" required placeholder="Motivo">
            <div><button class="btn btn-danger" type="submit">Cancelar</button></div>
        </form>
    </section>
    @endif
</div>
@endif

@if ($p->status === 'cancelled')<div class="alert alert-info mt-2">Cancelada em {{ $p->cancelled_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }} — {{ $p->cancel_reason }}</div>@endif

<section class="card mt-2">
    <div class="card__head"><h2>Pagamentos</h2></div>
    @include('finance._transactions', ['transactions' => $p->transactions])
</section>
@endsection
