@extends('layouts.app')
@section('title', 'Painel')
@section('content')
<div class="top">
  <div><h1>Olá, {{ explode(' ', auth()->user()->name)[0] }} 👋</h1><div class="sub">Você atendeu {{ $mine }} cotação(ões) hoje.</div></div>
  <div class="row auto"><a class="btn p" href="{{ route('quotes.create') }}">+ Nova cotação</a><a class="btn" href="{{ route('inbox') }}">💬 Conversas @if($unread)<span class="pill-dot">{{ $unread }}</span>@endif</a></div>
</div>

<div class="grid g4" style="margin-bottom:16px">
  <div class="kpi"><div class="l">Cotações hoje</div><div class="v">{{ $today['quotes'] }}</div><div class="s">{{ $today['orders'] }} viraram pedido · {{ $today['lost'] }} perdidas</div></div>
  <div class="kpi"><div class="l">Vendas hoje</div><div class="v">{{ money($today['orders_total']) }}</div><div class="s">ticket médio {{ money($today['ticket']) }}</div></div>
  <div class="kpi"><div class="l">Vendas no mês</div><div class="v">{{ money($month['orders_total']) }}</div><div class="s">{{ $month['orders'] }} pedidos · comissão {{ money($month['commission']) }}</div></div>
  <div class="kpi"><div class="l">Tempos médios hoje</div><div class="v" style="font-size:16px">⏱ {{ minutes_label($today['request_to_dispatch']) }} p/ enviar</div><div class="s">farmácias respondem em {{ minutes_label($today['pharmacy_response']) }} · finaliza em {{ minutes_label($today['request_to_close']) }}</div></div>
</div>

@if($issues->isNotEmpty())
<div class="card" style="border-color:#f4b9bb"><h2>⚠️ Entregas com problema ({{ $issues->count() }})</h2><table>
  @foreach($issues as $o)<tr><td><a href="{{ route('orders.show', $o) }}"><b>{{ $o->code }}</b></a></td><td>{{ $o->customer->displayName() }}</td><td>{{ $o->pharmacy->trade_name }}</td><td>{{ $o->delivery_issue }}</td><td class="small nowrap">{{ $o->delivery_issue_at->diffForHumans() }}</td></tr>@endforeach
</table></div>
@endif

<div class="grid g4" style="margin-bottom:16px">
  <div class="kpi"><div class="l">Indicação no mês (NPS)</div><div class="v">{{ $postSale['nps'] ?? '—' }}</div><div class="s">{{ $postSale['nps_count'] }} resposta(s) · {{ $postSale['nps_promoters'] }} promotores · {{ $postSale['nps_detractors'] }} detratores</div></div>
  <div class="kpi"><div class="l">Entregas confirmadas pelo cliente</div><div class="v">{{ $postSale['ps_delivery_confirmed'] }}/{{ $postSale['ps_orders'] }}</div><div class="s">pedidos do mês</div></div>
  <div class="kpi"><div class="l">Pedidos com nota fiscal</div><div class="v">{{ $postSale['ps_receipt_rate'] !== null ? number_format($postSale['ps_receipt_rate'], 0).'%' : '—' }}</div><div class="s">{{ $postSale['ps_with_receipt'] }} de {{ $postSale['ps_orders'] }} pedidos</div></div>
  <div class="kpi"><div class="l">Compraram sem pedido</div><div class="v">{{ $postSale['ps_bought_without_order'] }}</div><div class="s">disseram "COMPREI" · {{ $postSale['ps_gave_up'] }} desistiram</div></div>
</div>

@if($receiptsPending || $promosPending || $redemptionsPending)
<div class="card"><div class="row">
  @if($receiptsPending)<a class="btn" href="{{ route('receipts.index') }}">📄 {{ $receiptsPending }} nota(s) para conferir</a>@endif
  @if($promosPending)<a class="btn" href="{{ route('promotions.index', ['status' => 'pendente']) }}">🔥 {{ $promosPending }} promoção(ões) para aprovar</a>@endif
  @if($redemptionsPending)<a class="btn" href="{{ route('loyalty') }}">🎁 {{ $redemptionsPending }} brinde(s) para entregar</a>@endif
</div></div>
@endif

<div class="card">
  <h2>Fila de cotações em aberto</h2>
  <div class="table-wrap"><table>
    <tr><th>Cotação</th><th>Cliente</th><th>Bairro</th><th>Situação</th><th>Farmácias</th><th>Aguardando há</th><th>Televendas</th><th></th></tr>
    @forelse($open as $q)
    <tr>
      <td class="nowrap"><a href="{{ route('quotes.show', $q) }}"><b>{{ $q->code }}</b></a></td>
      <td>{{ $q->customer->displayName() }}</td>
      <td>{{ $q->customer->neighborhood?->name ?? '—' }}</td>
      <td><span class="badge b-{{ $q->status }}">{{ $q->statusLabel() }}</span></td>
      <td>{{ $q->answered_count }}/{{ $q->dispatches_count }}</td>
      <td class="nowrap">{{ $q->requested_at?->diffForHumans(null, true) }}</td>
      <td>{{ $q->operator?->name ?? '—' }}</td>
      <td><a class="btn sm p" href="{{ route('quotes.show', $q) }}">Atender</a></td>
    </tr>
    @empty
    <tr><td colspan="8" class="empty">Nenhuma cotação em aberto. 🎉</td></tr>
    @endforelse
  </table></div>
</div>

<div class="card">
  <h2>Televendas hoje</h2>
  <div class="table-wrap"><table>
    <tr><th>Atendente</th><th class="num">Atendimentos</th><th class="num">Pedidos</th><th class="num">Vendas</th><th class="num">Até envio</th><th class="num">Até finalizar</th><th class="num">Avaliação</th><th class="num">Ações</th></tr>
    @forelse($operators as $r)
    <tr><td>{{ $r['user']->name }}</td><td class="num">{{ $r['attendances'] }}</td><td class="num">{{ $r['orders'] }}</td><td class="num">{{ money($r['sales_total']) }}</td>
      <td class="num">{{ minutes_label($r['avg_to_dispatch']) }}</td><td class="num">{{ minutes_label($r['avg_to_close']) }}</td>
      <td class="num">{{ $r['feedback_avg'] ? number_format($r['feedback_avg'], 1, ',', '').' ★' : '—' }}</td><td class="num">{{ $r['actions'] }}</td></tr>
    @empty
    <tr><td colspan="8" class="empty">Sem atendimentos hoje.</td></tr>
    @endforelse
  </table></div>
</div>
@endsection
