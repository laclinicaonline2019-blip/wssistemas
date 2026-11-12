@extends('layouts.app')
@section('title', 'Pedido '.$order->code)
@section('content')
<div class="top">
  <div><h1>Pedido {{ $order->code }} <span class="badge b-{{ $order->status }}">{{ \App\Models\Order::STATUSES[$order->status] }}</span></h1>
    <div class="sub">{{ $order->created_at->format('d/m/Y H:i') }} · @if($order->quote)cotação <a href="{{ route('quotes.show', $order->quote) }}">{{ $order->quote->code }}</a> · @endif televendas {{ $order->operator?->name ?? '—' }}</div></div>
  <div class="row auto">
    @if($order->status === 'confirmado')
      <form method="post" action="{{ route('orders.complete', $order) }}">@csrf<button class="btn g">✔ Entregue / retirado</button></form>
      @unless($order->commission_statement_id)<form method="post" action="{{ route('orders.cancel', $order) }}">@csrf<button class="btn r" data-confirm="Cancelar o pedido?">Cancelar</button></form>@endunless
    @endif
  </div>
</div>
<div class="grid g3">
  <div class="card"><h3>Cliente</h3><a href="{{ route('customers.show', $order->customer) }}"><b>{{ $order->customer->displayName() }}</b></a>
    <div class="small">{{ \App\Support\Text::formatPhone($order->customer->phone) }}</div><div class="small">{{ $order->quote?->delivery_address ?: $order->customer->fullAddress() }}</div></div>
  <div class="card"><h3>Farmácia</h3><b>{{ $order->pharmacy->trade_name }}</b><div class="small">{{ $order->pharmacy->fullAddress() }}</div>
    <div class="small">{{ $order->fulfillment === 'entrega' ? '🛵 Entrega' : '🏪 Retirada' }}</div></div>
  <div class="card"><h3>Comissão</h3><div class="big">{{ money($order->commission_amount) }}</div>
    <div class="small muted">{{ number_format($order->commission_percent, 2, ',', '') }}% sobre {{ money($order->commission_base) }} {{ $order->receipt_total !== null ? '(total da nota fiscal)' : '(total do pedido — aguardando nota)' }}</div>
    @if($order->statement)<div class="small">Fechamento <a href="{{ route('commissions.show', $order->statement) }}">#{{ $order->statement->id }}</a></div>@endif</div>
</div>
<div class="card table-wrap"><h3>Itens</h3><table>
  <tr><th>Produto</th><th class="num">Qtd</th><th class="num">Unitário</th><th class="num">Total</th></tr>
  @foreach($order->items as $i)<tr><td>{{ $i->description }} @if($i->is_suggestion)<span class="badge b-aprovada">promoção</span>@endif</td><td class="num">{{ $i->quantity }}</td><td class="num">{{ money($i->unit_price) }}</td><td class="num">{{ money($i->total) }}</td></tr>@endforeach
  <tr><td colspan="3">Entrega</td><td class="num">{{ money($order->delivery_fee) }}</td></tr>
  <tr><td colspan="3"><b>Total</b></td><td class="num price">{{ money($order->total) }}</td></tr>
</table></div>
<div class="card"><h3>Notas fiscais da compra</h3>
  @forelse($order->receipts as $r)<div><a href="{{ route('receipts.show', $r) }}">{{ $r->code }}</a> <span class="badge b-{{ $r->status }}">{{ $r->statusLabel() }}</span> {{ $r->total ? money($r->total) : '' }}</div>
  @empty<div class="muted small">O cliente ainda não enviou a nota fiscal.</div>@endforelse
</div>
@endsection
