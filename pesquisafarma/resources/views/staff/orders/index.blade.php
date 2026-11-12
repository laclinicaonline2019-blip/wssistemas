@extends('layouts.app')
@section('title', 'Pedidos')
@section('content')
<div class="top"><h1>Pedidos (vendas)</h1></div>
<form class="toolbar">
  <select name="status"><option value="">Todas</option>@foreach(\App\Models\Order::STATUSES as $k => $v)<option value="{{ $k }}" @selected(request('status')===$k)>{{ $v }}</option>@endforeach</select>
  <select name="farmacia"><option value="">Todas as farmácias</option>@foreach($pharmacies as $p)<option value="{{ $p->id }}" @selected(request('farmacia')==$p->id)>{{ $p->trade_name }}</option>@endforeach</select>
  <label class="check"><input type="checkbox" name="pendencia" value="1" @checked(request('pendencia'))> com problema na entrega</label>
  <input type="date" name="de" value="{{ request('de') }}"><input type="date" name="ate" value="{{ request('ate') }}"><button class="btn">Filtrar</button>
</form>
<div class="card table-wrap"><table>
  <tr><th>Pedido</th><th>Data</th><th>Cliente</th><th>Farmácia</th><th>Situação</th><th class="num">Total</th><th class="num">Nota fiscal</th><th class="num">Comissão</th><th>Televendas</th></tr>
  @forelse($orders as $o)
  <tr><td><a href="{{ route('orders.show', $o) }}"><b>{{ $o->code }}</b></a></td><td class="small nowrap">{{ $o->created_at->format('d/m/Y H:i') }}</td>
    <td>{{ $o->customer->displayName() }}<div class="small muted">{{ $o->customer->neighborhood?->name }}</div></td><td>{{ $o->pharmacy->trade_name }}</td>
    <td><span class="badge b-{{ $o->status }}">{{ \App\Models\Order::STATUSES[$o->status] }}</span>@if($o->hasOpenIssue())<br><span class="badge b-cancelado" title="{{ $o->delivery_issue }}">⚠ entrega</span>@endif @if($o->delivery_confirmed_at)<br><span class="small muted">cliente confirmou</span>@endif</td><td class="num">{{ money($o->total) }}</td>
    <td class="num">{{ $o->receipt_total !== null ? money($o->receipt_total) : '—' }}</td><td class="num">{{ money($o->commission_amount) }}</td><td>{{ $o->operator?->name }}</td></tr>
  @empty <tr><td colspan="9" class="empty">Nenhum pedido.</td></tr>
  @endforelse
</table>{{ $orders->links() }}</div>
@endsection
