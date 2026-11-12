@extends('layouts.app')
@section('title', 'Fechamento #'.$st->id)
@section('content')
<div class="top"><div><h1>Fechamento #{{ $st->id }} — {{ $st->pharmacy->trade_name }} <span class="badge b-{{ $st->status }}">{{ \App\Models\CommissionStatement::STATUSES[$st->status] }}</span></h1>
  <div class="sub">{{ $st->period_start->format('d/m/Y') }} a {{ $st->period_end->format('d/m/Y') }} · {{ $st->cycle }} · CNPJ {{ $st->pharmacy->cnpj }}</div></div>
  <div class="row auto">
    @if($st->status === 'aberto')<form method="post" action="{{ route('commissions.status', $st) }}">@csrf<input type="hidden" name="status" value="emitido"><button class="btn">Marcar cobrança emitida</button></form>@endif
    @if(in_array($st->status, ['aberto','emitido']))<form method="post" action="{{ route('commissions.status', $st) }}">@csrf<input type="hidden" name="status" value="pago"><button class="btn g">Marcar como pago</button></form>
    <form method="post" action="{{ route('commissions.status', $st) }}">@csrf<input type="hidden" name="status" value="cancelado"><button class="btn r" data-confirm="Cancelar o fechamento? As vendas voltam para 'em aberto'.">Cancelar</button></form>@endif
    <a class="btn" href="#" onclick="window.print();return false">🖨 Imprimir</a>
  </div>
</div>
<div class="grid g3" style="margin-bottom:16px">
  <div class="kpi"><div class="l">Vendas</div><div class="v">{{ $st->sales_count }}</div></div>
  <div class="kpi"><div class="l">Base (tudo o que o cliente comprou)</div><div class="v">{{ money($st->base_total) }}</div></div>
  <div class="kpi"><div class="l">Comissão a receber</div><div class="v">{{ money($st->commission_amount) }}</div></div>
</div>
<div class="card table-wrap"><table>
  <tr><th>Data</th><th>Venda</th><th>Cliente</th><th class="num">Pedido</th><th class="num">Nota fiscal</th><th class="num">Base</th><th class="num">%</th><th class="num">Comissão</th></tr>
  @foreach($st->orders as $o)<tr><td class="small">{{ $o->created_at->format('d/m/Y') }}</td><td><a href="{{ route('orders.show', $o) }}">{{ $o->code }}</a></td><td>{{ $o->customer->displayName() }}</td><td class="num">{{ money($o->total) }}</td><td class="num">{{ $o->receipt_total !== null ? money($o->receipt_total) : '—' }}</td><td class="num">{{ money($o->commission_base) }}</td><td class="num">{{ number_format($o->commission_percent, 2, ',', '') }}</td><td class="num">{{ money($o->commission_amount) }}</td></tr>@endforeach
  @foreach($st->receipts as $r)<tr><td class="small">{{ $r->purchase_date?->format('d/m/Y') }}</td><td><a href="{{ route('receipts.show', $r) }}">{{ $r->code }}</a> <span class="small muted">compra com cartão, sem cotação</span></td><td>{{ $r->customer->displayName() }}</td><td class="num">—</td><td class="num">{{ money($r->total) }}</td><td class="num">{{ money($r->total) }}</td><td class="num">{{ number_format($r->commission_percent, 2, ',', '') }}</td><td class="num">{{ money($r->commission_amount) }}</td></tr>@endforeach
</table></div>
@endsection
