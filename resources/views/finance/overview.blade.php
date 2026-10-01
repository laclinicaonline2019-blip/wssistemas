@extends('layouts.app', ['title' => 'Financeiro'])

@php
    use App\Core\Support\Format;
    $me = auth()->user();
    $maxCat = max(1, collect($flow['by_category'])->map(fn ($v) => abs($v))->max() ?? 1);
    $maxDay = max(1, collect($flow['by_day'])->map(fn ($v) => abs($v))->max() ?? 1);
@endphp

@section('content')
@include('finance._tabs')
<div class="page-head">
    <div><h1>Financeiro</h1><p>Fluxo de caixa realizado de {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} a {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }} (estornos já descontados).</p></div>
    <form method="get" class="row">
        <label class="sr-only" for="ff">De</label><input id="ff" type="date" name="from" value="{{ $from }}" class="input w-auto">
        <label class="sr-only" for="ft">Até</label><input id="ft" type="date" name="to" value="{{ $to }}" class="input w-auto">
        @if ($branches->count() > 1)
            <label class="sr-only" for="fb">Unidade</label>
            <select id="fb" name="branch_id" class="input w-auto"><option value="">Todas as unidades</option>@foreach ($branches as $b)<option value="{{ $b->id }}" @selected($branchId === $b->id)>{{ $b->name }}</option>@endforeach</select>
        @endif
        <button class="btn" type="submit">Filtrar</button>
        <button class="btn" type="submit" name="export" value="csv">Exportar CSV</button>
    </form>
</div>

<div class="grid grid-4">
    <div class="card kpi"><div class="kpi__label">Entradas no período</div><div class="kpi__value kpi-pos">{{ Format::money($flow['in']) }}</div><div class="kpi__hint">hoje: {{ Format::money($today['in']) }}</div></div>
    <div class="card kpi"><div class="kpi__label">Saídas no período</div><div class="kpi__value kpi-neg">{{ Format::money($flow['out']) }}</div><div class="kpi__hint">hoje: {{ Format::money($today['out']) }}</div></div>
    <div class="card kpi"><div class="kpi__label">Saldo do período</div><div class="kpi__value {{ $flow['net'] < 0 ? 'kpi-neg' : '' }}">{{ Format::money($flow['net']) }}</div><div class="kpi__hint">entradas − saídas</div></div>
    <a class="card kpi kpi-link" href="{{ route('cash.sessions') }}"><div class="kpi__label">Caixas a conferir</div><div class="kpi__value">{{ $kpi['sessions_to_review'] }}</div><div class="kpi__hint">{{ $kpi['sessions_open'] }} aberto(s) agora</div></a>
    <a class="card kpi kpi-link" href="{{ route('receivables.index') }}"><div class="kpi__label">A receber (em aberto)</div><div class="kpi__value">{{ Format::money((int) $kpi['receivable_open']) }}</div><div class="kpi__hint">&nbsp;</div></a>
    <a class="card kpi kpi-link" href="{{ route('receivables.index', ['status' => 'overdue']) }}"><div class="kpi__label">Recebimentos vencidos</div><div class="kpi__value {{ $kpi['receivable_overdue'] > 0 ? 'kpi-neg' : '' }}">{{ Format::money((int) $kpi['receivable_overdue']) }}</div><div class="kpi__hint">{{ $kpi['receivable_overdue_count'] }} conta(s)</div></a>
    <a class="card kpi kpi-link" href="{{ route('payables.index') }}"><div class="kpi__label">A pagar em 7 dias</div><div class="kpi__value">{{ Format::money((int) $kpi['payable_week']) }}</div><div class="kpi__hint">&nbsp;</div></a>
    <a class="card kpi kpi-link" href="{{ route('payables.index', ['status' => 'overdue']) }}"><div class="kpi__label">Contas a pagar vencidas</div><div class="kpi__value {{ $kpi['payable_overdue'] > 0 ? 'kpi-neg' : '' }}">{{ Format::money((int) $kpi['payable_overdue']) }}</div><div class="kpi__hint">&nbsp;</div></a>
</div>

<div class="grid grid-2 mt-2">
    <section class="card">
        <div class="card__head"><h2>Por forma de pagamento</h2></div>
        <div class="table-wrap"><table class="table"><tbody>
            @forelse ($flow['by_method'] as $m => $v)
                <tr><td>{{ $methods[$m] ?? $m }}</td><td class="t-right {{ $v < 0 ? 'text-danger' : '' }}">{{ Format::money($v) }}</td></tr>
            @empty
                <tr><td class="empty">Sem movimentações no período.</td></tr>
            @endforelse
        </tbody></table></div>
    </section>
    <section class="card">
        <div class="card__head"><h2>Por categoria</h2></div>
        <div class="card__body">
            @forelse ($flow['by_category'] as $cat => $v)
                <div class="bar-row"><span>{{ $cat }}</span><div class="meter"><span data-pct="{{ round(abs($v) / $maxCat * 100) }}"></span></div><span class="t-right {{ $v < 0 ? 'text-danger' : 'text-success' }}">{{ Format::money($v) }}</span></div>
            @empty
                <p class="small muted">Sem movimentações no período.</p>
            @endforelse
        </div>
    </section>
</div>

<section class="card mt-2">
    <div class="card__head"><h2>Saldo por dia</h2></div>
    <div class="card__body">
        @forelse ($flow['by_day'] as $day => $v)
            <div class="bar-row"><span>{{ \Carbon\Carbon::parse($day)->translatedFormat('D, d/m') }}</span><div class="meter"><span data-pct="{{ round(abs($v) / $maxDay * 100) }}"></span></div><span class="t-right {{ $v < 0 ? 'text-danger' : '' }}">{{ Format::money($v) }}</span></div>
        @empty
            <p class="small muted">Sem movimentações no período.</p>
        @endforelse
    </div>
</section>

<div class="row mt-2">
    @if ($me->hasPermission('financeiro.editar'))<a class="btn" href="{{ route('finance.categories') }}">Plano de contas (categorias)</a>@endif
</div>
@endsection
