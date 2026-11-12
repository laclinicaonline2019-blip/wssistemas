@extends('layouts.app')
@section('title', 'Vendas e comissão')
@section('content')
<div class="top"><div><h1>Vendas e comissão</h1><div class="sub">{{ $svc->from->format('d/m/Y') }} a {{ $svc->to->format('d/m/Y') }}</div></div>
<form class="toolbar">@include('partials.period')<button class="btn">Aplicar</button></form></div>
<div class="grid g4" style="margin-bottom:16px">
  <div class="kpi"><div class="l">Pedidos</div><div class="v">{{ $row['orders'] ?? 0 }}</div><div class="s">{{ money($row['orders_total'] ?? 0) }}</div></div>
  <div class="kpi"><div class="l">Notas fiscais conferidas</div><div class="v">{{ $row['receipts'] ?? 0 }}</div><div class="s">{{ money($row['receipts_total'] ?? 0) }}</div></div>
  <div class="kpi"><div class="l">Base de comissão</div><div class="v">{{ money($row['commission_base'] ?? 0) }}</div></div>
  <div class="kpi"><div class="l">Comissão ({{ number_format($row['percent'] ?? 0, 1, ',', '') }}%)</div><div class="v">{{ money($row['commission'] ?? 0) }}</div></div>
</div>
<div class="card table-wrap"><h2>Pedidos do período</h2><table>
  <tr><th>Data</th><th>Pedido</th><th>Cliente</th><th>Situação</th><th class="num">Pedido</th><th class="num">Nota</th><th class="num">Comissão</th></tr>
  @forelse($orders as $o)<tr><td class="small">{{ $o->created_at->format('d/m/Y') }}</td><td>{{ $o->code }}</td><td>{{ $o->customer->displayName() }}</td><td><span class="badge b-{{ $o->status }}">{{ \App\Models\Order::STATUSES[$o->status] }}</span></td>
    <td class="num">{{ money($o->total) }}</td><td class="num">{{ $o->receipt_total !== null ? money($o->receipt_total) : '—' }}</td><td class="num">{{ money($o->commission_amount) }}</td></tr>
  @empty <tr><td colspan="7" class="empty">Sem pedidos no período.</td></tr>@endforelse
</table></div>
<div class="card table-wrap"><h2>Fechamentos</h2><table>
  <tr><th>Período</th><th class="num">Vendas</th><th class="num">Base</th><th class="num">Comissão</th><th>Situação</th></tr>
  @forelse($statements as $s)<tr><td>{{ $s->period_start->format('d/m/Y') }} a {{ $s->period_end->format('d/m/Y') }}</td><td class="num">{{ $s->sales_count }}</td><td class="num">{{ money($s->base_total) }}</td><td class="num">{{ money($s->commission_amount) }}</td><td><span class="badge b-{{ $s->status }}">{{ \App\Models\CommissionStatement::STATUSES[$s->status] }}</span></td></tr>
  @empty <tr><td colspan="5" class="empty">Nenhum fechamento.</td></tr>@endforelse
</table></div>
@endsection
