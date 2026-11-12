@extends('layouts.app')
@section('title', 'Pedidos')
@section('content')
<div class="top"><h1>Pedidos recebidos</h1></div>
@forelse($orders as $o)
<div class="card">
  <div class="top" style="margin-bottom:6px"><div><b>{{ $o->code }}</b> <span class="badge b-{{ $o->status }}">{{ \App\Models\Order::STATUSES[$o->status] }}</span> <span class="small muted">{{ $o->created_at->format('d/m/Y H:i') }}</span>
    <div class="small">👤 {{ $o->customer->displayName() }} · {{ \App\Support\Text::formatPhone($o->customer->phone) }} · {{ $o->fulfillment === 'entrega' ? '🛵 '.($o->quote?->delivery_address ?: $o->customer->fullAddress()) : '🏪 retirada' }}</div></div>
    <div class="num"><div class="price">{{ money($o->total) }}</div>@if($o->status === 'confirmado')<form method="post" action="{{ route('panel.orders.complete', $o) }}">@csrf<button class="btn sm g">Entregue / retirado</button></form>@endif</div></div>
  @if($o->hasOpenIssue())<div class="alert err small">⚠️ {{ $o->delivery_issue }} ({{ $o->delivery_issue_at->format('d/m H:i') }})</div>@endif
  @if($o->delivery_confirmed_at)<div class="small" style="color:var(--green)">✔ Cliente confirmou o recebimento em {{ $o->delivery_confirmed_at->format('d/m H:i') }}</div>@endif
  <table>@foreach($o->items as $i)<tr><td class="small">{{ $i->quantity }}x {{ $i->description }}</td><td class="num small">{{ money($i->total) }}</td></tr>@endforeach
    <tr><td class="small">Entrega</td><td class="num small">{{ money($o->delivery_fee) }}</td></tr></table>
</div>
@empty <div class="card empty">Nenhum pedido ainda.</div>
@endforelse
{{ $orders->links() }}
@endsection
