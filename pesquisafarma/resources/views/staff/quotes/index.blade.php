@extends('layouts.app')
@section('title', 'Cotações')
@section('content')
<div class="top"><div><h1>Cotações</h1></div><a class="btn p" href="{{ route('quotes.create') }}">+ Nova cotação</a></div>
<form class="toolbar">
  <input name="q" value="{{ request('q') }}" placeholder="Código, cliente ou telefone">
  <select name="status"><option value="">Todas as situações</option>@foreach(\App\Models\Quote::STATUSES as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select>
  <select name="operador"><option value="">Todos os televendas</option>@foreach(\App\Models\User::whereIn('role',['admin','televendas'])->orderBy('name')->get() as $u)<option value="{{ $u->id }}" @selected(request('operador') == $u->id)>{{ $u->name }}</option>@endforeach</select>
  <button class="btn">Filtrar</button>
</form>
<div class="card table-wrap"><table>
  <tr><th>Cotação</th><th>Data</th><th>Cliente</th><th>Bairro</th><th>Situação</th><th class="num">Respostas</th><th>Pedido</th><th>Televendas</th><th>Avaliação</th></tr>
  @forelse($quotes as $q)
  <tr>
    <td class="nowrap"><a href="{{ route('quotes.show', $q) }}"><b>{{ $q->code }}</b></a><div class="small muted">{{ $q->channel }}</div></td>
    <td class="nowrap small">{{ $q->requested_at?->format('d/m H:i') }}</td>
    <td>{{ $q->customer->displayName() }}</td><td>{{ $q->customer->neighborhood?->name }}</td>
    <td><span class="badge b-{{ $q->status }}">{{ $q->statusLabel() }}</span></td>
    <td class="num">{{ $q->answered_count }}/{{ $q->dispatches_count }}</td>
    <td>@if($q->order)<a href="{{ route('orders.show', $q->order) }}">{{ money($q->order->total) }}</a><div class="small muted">{{ $q->order->pharmacy->trade_name }}</div>@endif</td>
    <td>{{ $q->operator?->name }}</td>
    <td class="stars">{{ $q->feedback_rating ? str_repeat('★', $q->feedback_rating) : '' }}</td>
  </tr>
  @empty <tr><td colspan="9" class="empty">Nenhuma cotação encontrada.</td></tr>
  @endforelse
</table>{{ $quotes->links() }}</div>
@endsection
