@extends('layouts.app', ['title' => 'Meu caixa'])

@php use App\Core\Support\Format; $tz = 'America/Sao_Paulo'; @endphp

@section('content')
@include('finance._tabs')
@if (! $session)
    <div class="page-head"><div><h1>Meu caixa</h1><p>Abra o caixa no início do turno informando o fundo de troco que está na gaveta.</p></div></div>
    <div class="grid grid-2">
        <section class="card">
            <div class="card__head"><h2>Abrir caixa</h2></div>
            <form method="post" action="{{ route('cash.open') }}" class="card__body form-grid">
                @csrf
                <div class="field col-6"><label for="co-b">Unidade</label>
                    <select id="co-b" name="branch_id" class="input">@foreach ($branches as $b)<option value="{{ $b->id }}" @selected(app(\App\Core\Tenancy\TenantContext::class)->branchId() === $b->id)>{{ $b->name }}</option>@endforeach</select></div>
                <x-field name="opening" label="Fundo de troco (R$)" col="col-6" mask="money" inputmode="numeric" value="0,00" />
                <div class="col-12 form-actions"><button class="btn btn-primary" type="submit">Abrir caixa</button></div>
            </form>
        </section>
        <section class="card">
            <div class="card__head"><h2>Últimos caixas</h2></div>
            <div class="card__body stack">
                @forelse ($recent as $s)
                    <div class="spread small"><a href="{{ route('cash.show', $s) }}">{{ $s->opened_at->timezone($tz)->format('d/m/Y H:i') }}</a>
                        <span><span class="badge {{ $s->status === 'reviewed' ? 'badge-success' : 'badge-warning' }}">{{ $s->status === 'reviewed' ? 'conferido' : 'aguardando conferência' }}</span>
                            @if ($s->difference_cents)<span class="badge badge-danger">dif. {{ Format::money($s->difference_cents) }}</span>@endif</span></div>
                @empty
                    <p class="small muted">Nenhum caixa anterior.</p>
                @endforelse
            </div>
        </section>
    </div>
@else
    <div class="page-head">
        <div><h1>Meu caixa — {{ $session->branch->name }}</h1><p>Aberto em {{ $session->opened_at->timezone($tz)->format('d/m/Y H:i') }} · fundo de troco {{ Format::money($session->opening_cents) }}</p></div>
        <div class="row"><a class="btn btn-primary" href="{{ route('receivables.index') }}">Receber de paciente</a></div>
    </div>

    <div class="grid grid-3">
        <div class="card kpi"><div class="kpi__label">Entradas</div><div class="kpi__value kpi-pos">{{ Format::money($summary['total_in']) }}</div><div class="kpi__hint">todas as formas</div></div>
        <div class="card kpi"><div class="kpi__label">Saídas</div><div class="kpi__value kpi-neg">{{ Format::money($summary['total_out']) }}</div><div class="kpi__hint">sangrias, pagamentos, estornos</div></div>
        <div class="card kpi"><div class="kpi__label">Movimentações</div><div class="kpi__value">{{ $transactions->count() }}</div><div class="kpi__hint">neste caixa</div></div>
    </div>

    <div class="grid grid-2 mt-2">
        <section class="card">
            <div class="card__head"><h2>Sangria / suprimento</h2></div>
            <form method="post" action="{{ route('cash.movement', $session) }}" class="card__body form-grid">
                @csrf
                <div class="field col-6"><label for="mv-k">Tipo</label>
                    <select id="mv-k" name="kind" class="input"><option value="withdrawal">Sangria (retirada)</option><option value="deposit">Suprimento (reforço de troco)</option></select></div>
                <x-field name="amount" label="Valor (R$)" col="col-6" mask="money" inputmode="numeric" />
                <x-field name="reason" label="Motivo" col="col-12" maxlength="255" placeholder="ex.: depósito no cofre, troco do banco" />
                <div class="col-12 form-actions"><button class="btn" type="submit">Registrar</button></div>
            </form>
        </section>
        <section class="card">
            <div class="card__head"><h2>Fechar caixa</h2></div>
            <form method="post" action="{{ route('cash.close', $session) }}" class="card__body form-grid" data-confirm="Fechar o caixa? Depois de fechado não é possível movimentá-lo.">
                @csrf
                <p class="help col-12"><strong>Fechamento cego:</strong> conte e informe o que há em cada forma (dinheiro = tudo que está na gaveta, inclusive o troco). O valor esperado só aparece depois do fechamento.</p>
                @foreach (collect(['cash', 'pix', 'debit_card', 'credit_card'])->merge(array_keys($summary['methods']))->unique() as $m)
                    <div class="field col-6"><label for="d-{{ $m }}">{{ $methods[$m] }}</label><input id="d-{{ $m }}" name="declared[{{ $m }}]" class="input" data-mask="money" inputmode="numeric" placeholder="0,00"></div>
                @endforeach
                <x-field name="notes" label="Observações do fechamento" col="col-12" maxlength="500" />
                <div class="col-12 form-actions"><button class="btn btn-primary" type="submit">Fechar caixa</button></div>
            </form>
        </section>
    </div>

    <section class="card mt-2">
        <div class="card__head"><h2>Movimentações do caixa</h2></div>
        @include('finance._transactions', ['transactions' => $transactions, 'showOrigin' => true])
    </section>
@endif
@endsection
