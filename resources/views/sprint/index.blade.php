@extends('layouts.app', ['title' => 'Melhor Sprint'])

@php
    use App\Core\Support\Format;
    use App\Modules\Sprint\Models\SprintSale;
    $tz = 'America/Sao_Paulo';
    $badge = ['open' => 'badge-warning', 'partial' => 'badge-info', 'paid' => 'badge-success', 'cancelled' => '', 'refunded' => 'badge-danger'];
@endphp

@section('content')
@include('sprint._head', ['subtitle' => 'Vendas, pagamentos e divisão do período'])

<form method="get" class="row mb-2">
    <label class="sr-only" for="mf">De</label><input id="mf" type="date" name="from" value="{{ $from }}" class="input w-auto">
    <label class="sr-only" for="mt">Até</label><input id="mt" type="date" name="to" value="{{ $to }}" class="input w-auto">
    <label class="sr-only" for="ms">Situação</label>
    <select id="ms" name="status" class="input w-auto"><option value="all">Todas as situações</option>@foreach (SprintSale::STATUSES as $k => $l)<option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach</select>
    <label class="sr-only" for="mq">Buscar</label><input id="mq" name="q" value="{{ $q }}" class="input w-auto" placeholder="Cliente, descrição ou nº">
    <button class="btn" type="submit">Filtrar</button>
</form>

<div class="grid grid-4">
    <div class="card kpi"><div class="kpi__label">Recebido no período</div><div class="kpi__value">{{ Format::money($received) }}</div><div class="kpi__hint">{{ $refunded ? 'estornado: '.Format::money($refunded) : 'pagamentos confirmados' }}</div></div>
    <div class="card kpi ms-kpi-pro"><div class="kpi__label">Parte dos profissionais</div><div class="kpi__value">{{ Format::money($kpi['professional'] ?? 0) }}</div><div class="kpi__hint">médicos e parceiros</div></div>
    <div class="card kpi ms-kpi-house"><div class="kpi__label">Ficou na clínica</div><div class="kpi__value">{{ Format::money($kpi['house'] ?? 0) }}</div><div class="kpi__hint">já descontadas tarifas e comissão</div></div>
    <div class="card kpi"><div class="kpi__label">Tarifas + comissão</div><div class="kpi__value">{{ Format::money(($kpi['fee'] ?? 0) + ($kpi['platform'] ?? 0)) }}</div><div class="kpi__hint">gateway {{ Format::money($kpi['fee'] ?? 0) }} · Melhor Sprint {{ Format::money($kpi['platform'] ?? 0) }}</div></div>
</div>

<section class="card mt-2">
    <div class="card__head"><h2>Vendas</h2></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Nº</th><th>Data</th><th>Cliente</th><th>Descrição</th><th class="t-right">Total</th><th class="t-right">Profissionais</th><th class="t-right">Clínica</th><th>Situação</th></tr></thead>
        <tbody>
        @forelse ($sales as $s)
            <tr>
                <td class="nowrap"><a href="{{ route('sprint.sales.show', $s) }}"><strong>{{ $s->code() }}</strong></a></td>
                <td class="small nowrap">{{ $s->created_at->timezone($tz)->format('d/m/Y H:i') }}</td>
                <td class="small">{{ $s->customerLabel() }}</td>
                <td class="small">{{ $s->description }}</td>
                <td class="t-right">{{ Format::money($s->total_cents) }}</td>
                <td class="t-right small">{{ Format::money($s->professional_cents) }}</td>
                <td class="t-right small">{{ Format::money($s->house_cents) }}</td>
                <td><span class="badge {{ $badge[$s->status] ?? '' }}">{{ $s->statusLabel() }}</span></td>
            </tr>
        @empty
            <tr><td colspan="8" class="empty">Nenhuma venda no período.@if (auth()->user()->hasPermission('sprint.vender')) <a href="{{ route('sprint.sales.create') }}">Fazer uma venda</a>.@endif</td></tr>
        @endforelse
        </tbody>
    </table></div>
    @include('partials.pagination', ['paginator' => $sales])
</section>
@endsection
