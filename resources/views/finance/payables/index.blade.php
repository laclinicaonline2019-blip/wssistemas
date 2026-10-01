@extends('layouts.app', ['title' => 'Contas a pagar'])

@php use App\Core\Support\Format; $me = auth()->user(); @endphp

@section('content')
@include('finance._tabs')
<div class="page-head"><div><h1>Contas a pagar</h1><p>Fornecedores, aluguel, salários, impostos, repasses. Parcelamento gera uma conta por mês.</p></div></div>

@if ($me->hasPermission('financeiro.editar'))
<details class="card mb-2" @if ($errors->any()) open @endif>
    <summary class="card__head"><h2>Lançar conta a pagar</h2></summary>
    <form method="post" action="{{ route('payables.store') }}">
        @csrf
        <div class="card__body form-grid">
            <x-field name="supplier" label="Fornecedor / favorecido" col="col-6" maxlength="150" required />
            <x-field name="description" label="Descrição" col="col-6" maxlength="180" required />
            <x-field name="amount" label="Valor total (R$)" col="col-3" mask="money" inputmode="numeric" required />
            <x-field name="installments" label="Parcelas" type="number" min="1" max="60" col="col-2" :value="1" />
            <x-field name="due_date" label="1º vencimento" type="date" col="col-3" required />
            <div class="field col-4"><label for="p-cat">Categoria</label>
                <select id="p-cat" name="category_id" class="input" required>@foreach ($categories as $c)<option value="{{ $c->id }}" @selected(old('category_id') === $c->id)>{{ $c->name }}</option>@endforeach</select></div>
            <div class="field col-4"><label for="p-br">Unidade</label>
                <select id="p-br" name="branch_id" class="input"><option value="">Empresa (geral)</option>@foreach ($branches as $b)<option value="{{ $b->id }}" @selected(old('branch_id') === $b->id)>{{ $b->name }}</option>@endforeach</select></div>
            <x-field name="document_number" label="Nº do documento (NF, boleto)" col="col-4" maxlength="60" />
            <x-field name="notes" label="Observações" col="col-4" maxlength="500" />
        </div>
        <div class="card__body form-actions"><button class="btn btn-primary" type="submit">Lançar</button></div>
    </form>
</details>
@endif

<form method="get" class="card mb-2"><div class="card__body row">
    <label class="sr-only" for="ps">Situação</label>
    <select id="ps" name="status" class="input w-auto" data-autosubmit>
        @foreach (['open' => 'Em aberto', 'overdue' => 'Vencidas', 'paid' => 'Pagas', 'cancelled' => 'Canceladas', 'all' => 'Todas'] as $k => $l)<option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach
    </select>
    <label class="sr-only" for="pq">Buscar</label><input id="pq" name="q" value="{{ request('q') }}" class="input w-auto" placeholder="Fornecedor ou descrição">
    <label class="sr-only" for="pf">De</label><input id="pf" type="date" name="from" value="{{ request('from') }}" class="input w-auto">
    <label class="sr-only" for="pt">Até</label><input id="pt" type="date" name="to" value="{{ request('to') }}" class="input w-auto">
    <button class="btn" type="submit">Filtrar</button>
</div></form>

<section class="card">
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Vencimento</th><th>Fornecedor / descrição</th><th class="hide-sm">Categoria</th><th class="t-right">Valor</th><th class="t-right">Saldo</th><th>Situação</th><th></th></tr></thead>
        <tbody>
        @forelse ($items as $p)
            <tr class="{{ $p->status === 'cancelled' ? 'muted' : '' }}">
                <td class="nowrap small">{{ $p->due_date->format('d/m/Y') }}</td>
                <td>{{ $p->supplier }}<div class="small muted">{{ $p->description }}{{ $p->document_number ? ' · '.$p->document_number : '' }}</div></td>
                <td class="hide-sm small">{{ $p->category->name }}</td>
                <td class="t-right nowrap">{{ Format::money($p->amount_cents) }}</td>
                <td class="t-right nowrap"><strong>{{ Format::money($p->balanceCents()) }}</strong></td>
                <td><span class="badge {{ ['paid' => 'badge-success', 'partial' => 'badge-info', 'cancelled' => ''][$p->status] ?? ($p->isOverdue() ? 'badge-danger' : 'badge-warning') }}">{{ $p->statusLabel() }}</span></td>
                <td class="actions"><a class="btn btn-sm" href="{{ route('payables.show', $p) }}">{{ in_array($p->status, ['open', 'partial'], true) && $me->hasPermission('financeiro.editar') ? 'Pagar' : 'Abrir' }}</a></td>
            </tr>
        @empty
            <tr><td colspan="7" class="empty">Nenhuma conta encontrada.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $items->links() }}
</section>
@endsection
