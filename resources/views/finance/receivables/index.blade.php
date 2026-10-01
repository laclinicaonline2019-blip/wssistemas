@extends('layouts.app', ['title' => 'Contas a receber'])

@php use App\Core\Support\Format; $me = auth()->user(); @endphp

@section('content')
@include('finance._tabs')
<div class="page-head">
    <div><h1>Contas a receber</h1><p>Consultas particulares geram a cobrança automaticamente na chegada do paciente.
        @if (! $openSession && $me->hasPermission('caixa.operar')) <a href="{{ route('cash.index') }}">Abra seu caixa</a> para receber em dinheiro.@endif</p></div>
    <div class="row">
        @if ($me->hasPermission('financeiro.editar') || $me->hasPermission('caixa.operar'))<a class="btn btn-primary" href="{{ route('receivables.create') }}">Nova conta a receber</a>@endif
    </div>
</div>

<form method="get" class="toolbar card mb-2"><div class="card__body row">
    <label class="sr-only" for="rs">Situação</label>
    <select id="rs" name="status" class="input w-auto" data-autosubmit>
        @foreach (['open' => 'Em aberto', 'overdue' => 'Vencidas', 'paid' => 'Recebidas', 'cancelled' => 'Canceladas', 'all' => 'Todas'] as $k => $l)<option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach
    </select>
    <label class="sr-only" for="rq">Buscar</label><input id="rq" name="q" value="{{ request('q') }}" class="input w-auto" placeholder="Paciente ou descrição">
    <label class="sr-only" for="rf">De</label><input id="rf" type="date" name="from" value="{{ request('from') }}" class="input w-auto">
    <label class="sr-only" for="rt">Até</label><input id="rt" type="date" name="to" value="{{ request('to') }}" class="input w-auto">
    <button class="btn" type="submit">Filtrar</button>
</div></form>

<section class="card">
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Vencimento</th><th>Paciente / descrição</th><th class="hide-sm">Unidade</th><th class="t-right">Valor</th><th class="t-right">Saldo</th><th>Situação</th><th></th></tr></thead>
        <tbody>
        @forelse ($items as $r)
            <tr class="{{ $r->status === 'cancelled' ? 'muted' : '' }}">
                <td class="nowrap small">{{ $r->due_date->format('d/m/Y') }}</td>
                <td>{{ $r->patient?->displayName() ?? '—' }}@if ($r->patient)<span class="record-no small"> #{{ $r->patient->record_number }}</span>@endif
                    <div class="small muted">{{ $r->description }}</div></td>
                <td class="hide-sm small">{{ $r->branch->name }}</td>
                <td class="t-right nowrap">{{ Format::money($r->amount_cents) }}</td>
                <td class="t-right nowrap"><strong>{{ Format::money($r->balanceCents()) }}</strong></td>
                <td><span class="badge {{ ['paid' => 'badge-success', 'partial' => 'badge-info', 'cancelled' => ''][$r->status] ?? ($r->isOverdue() ? 'badge-danger' : 'badge-warning') }}">{{ $r->statusLabel() }}</span></td>
                <td class="actions"><a class="btn btn-sm {{ in_array($r->status, ['open', 'partial'], true) ? 'btn-primary' : '' }}" href="{{ route('receivables.show', $r) }}">{{ in_array($r->status, ['open', 'partial'], true) ? 'Receber' : 'Abrir' }}</a></td>
            </tr>
        @empty
            <tr><td colspan="7" class="empty">Nenhuma conta encontrada.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $items->links() }}
</section>
@endsection
