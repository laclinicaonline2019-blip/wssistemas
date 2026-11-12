@extends('layouts.app')
@section('title', 'Relatórios')
@section('content')
<div class="top"><div><h1>Relatórios</h1><div class="sub">{{ $svc->from->format('d/m/Y') }} a {{ $svc->to->format('d/m/Y') }}</div></div></div>
<div class="tabs">@foreach(\App\Http\Controllers\Staff\ReportController::REPORTS as $k => $v)<a href="{{ route('reports', $k) }}?{{ http_build_query(request()->only('de','ate')) }}" class="{{ $report === $k ? 'on' : '' }}">{{ $v }}</a>@endforeach</div>
<form class="toolbar">
  @include('partials.period')
  @if(in_array($report, ['farmacias','extrato']))<div><label>Farmácia</label><select name="farmacia"><option value="">Todas</option>@foreach($pharmacies as $p)<option value="{{ $p->id }}" @selected(request('farmacia')==$p->id)>{{ $p->trade_name }}</option>@endforeach</select></div>@endif
  @if($report === 'extrato')<div><label>Cliente</label><select name="cliente"><option value="">Todos</option>@foreach($customers as $c)<option value="{{ $c->id }}" @selected(request('cliente')==$c->id)>{{ $c->displayName() }}</option>@endforeach</select></div>@endif
  @if($report === 'clientes')<div><label>Cliente</label><input name="q" value="{{ request('q') }}" placeholder="Nome ou telefone"></div>@endif
  <button class="btn p">Aplicar</button>
  @if($report !== 'tempos')<a class="btn" href="{{ request()->fullUrlWithQuery(['csv' => 1]) }}">⬇ Excel (CSV)</a>@endif
  <a class="btn" href="#" onclick="window.print();return false">🖨 Imprimir</a>
</form>

@if($report === 'farmacias')
<div class="card table-wrap"><table>
  <tr><th>Farmácia</th><th class="num">Cotações</th><th class="num">Respondeu</th><th class="num">Tempo médio</th><th class="num">Pedidos</th><th class="num">Total pedidos</th><th class="num">Notas</th><th class="num">Total notas</th><th class="num">Base</th><th class="num">%</th><th class="num">Comissão</th></tr>
  @forelse($rows as $r)
  <tr><td><b>{{ $r['pharmacy']->trade_name }}</b><div class="small muted">{{ $r['pharmacy']->neighborhood?->name }} · {{ $r['cycle'] }}</div></td>
    <td class="num">{{ $r['quotes_received'] }}</td><td class="num">{{ $r['response_rate'] !== null ? $r['response_rate'].'%' : '—' }}</td><td class="num">{{ minutes_label($r['avg_response_min']) }}</td>
    <td class="num">{{ $r['orders'] }}</td><td class="num">{{ money($r['orders_total']) }}</td><td class="num">{{ $r['receipts'] }}</td><td class="num">{{ money($r['receipts_total']) }}</td>
    <td class="num">{{ money($r['commission_base']) }}</td><td class="num">{{ number_format($r['percent'], 1, ',', '') }}</td><td class="num"><b>{{ money($r['commission']) }}</b></td></tr>
  @empty <tr><td colspan="11" class="empty">Sem movimento no período.</td></tr>@endforelse
  @if($rows->isNotEmpty())<tr><th>Total</th><th class="num">{{ $rows->sum('quotes_received') }}</th><th></th><th></th><th class="num">{{ $rows->sum('orders') }}</th><th class="num">{{ money($rows->sum('orders_total')) }}</th><th class="num">{{ $rows->sum('receipts') }}</th><th class="num">{{ money($rows->sum('receipts_total')) }}</th><th class="num">{{ money($rows->sum('commission_base')) }}</th><th></th><th class="num">{{ money($rows->sum('commission')) }}</th></tr>@endif
</table></div>
@elseif($report === 'clientes')
<div class="card table-wrap"><table>
  <tr><th>Cliente</th><th>Bairro</th><th class="num">Cotações</th><th class="num">Pedidos</th><th class="num">Conversão</th><th class="num">Total pedidos</th><th class="num">Notas</th><th class="num">Total notas</th><th class="num">Pontos</th></tr>
  @forelse($rows as $r)
  <tr><td><a href="{{ route('customers.show', $r['customer']) }}">{{ $r['customer']->displayName() }}</a><div class="small muted">{{ \App\Support\Text::formatPhone($r['customer']->phone) }}</div></td><td>{{ $r['customer']->neighborhood?->name }}</td>
    <td class="num">{{ $r['quotes'] }}</td><td class="num">{{ $r['orders'] }}</td><td class="num">{{ $r['conversion'] !== null ? $r['conversion'].'%' : '—' }}</td><td class="num">{{ money($r['orders_total']) }}</td>
    <td class="num">{{ $r['receipts'] }}</td><td class="num">{{ money($r['receipts_total']) }}</td><td class="num">{{ $r['customer']->points_balance }}</td></tr>
  @empty <tr><td colspan="9" class="empty">Sem movimento.</td></tr>@endforelse
</table></div>
@elseif($report === 'cotado-comprado')
<div class="card table-wrap"><table>
  <tr><th>Nota</th><th>Cliente</th><th>Farmácia</th><th>Pedido</th><th class="num">Cotado</th><th class="num">Comprado</th><th class="num">Diferença</th><th class="num">Itens fora da cotação</th></tr>
  @forelse($rows as $r)
  <tr><td><a href="{{ route('receipts.show', $r['receipt']) }}">{{ $r['receipt']->code }}</a></td><td>{{ $r['receipt']->customer->displayName() }}</td><td>{{ $r['receipt']->pharmacy?->trade_name }}</td>
    <td><a href="{{ route('orders.show', $r['receipt']->order) }}">{{ $r['receipt']->order->code }}</a></td><td class="num">{{ money($r['quoted']) }}</td><td class="num">{{ money($r['bought']) }}</td>
    <td class="num" style="color:{{ $r['difference'] >= 0 ? 'var(--green)' : 'var(--red)' }}">{{ money($r['difference']) }}</td><td class="num">{{ money($r['extra_items_total']) }}</td></tr>
  @empty <tr><td colspan="8" class="empty">Nenhuma nota ligada a pedido no período.</td></tr>@endforelse
  @if($rows->isNotEmpty())<tr><th colspan="4">Total</th><th class="num">{{ money($rows->sum('quoted')) }}</th><th class="num">{{ money($rows->sum('bought')) }}</th><th class="num">{{ money($rows->sum('difference')) }}</th><th class="num">{{ money($rows->sum('extra_items_total')) }}</th></tr>@endif
</table></div>
@elseif($report === 'televendas')
<div class="card table-wrap"><table>
  <tr><th>Televendas</th><th class="num">Atendimentos</th><th class="num">Pedidos</th><th class="num">Conversão</th><th class="num">Vendas</th><th class="num">Pedido → envio</th><th class="num">Pedido → apresentar</th><th class="num">Pedido → finalizar</th><th class="num">Avaliação</th><th class="num">Ações</th><th class="num">Mensagens</th></tr>
  @forelse($rows as $r)
  <tr><td><a href="{{ route('activity', ['usuario' => $r['user']->id]) }}">{{ $r['user']->name }}</a></td><td class="num">{{ $r['attendances'] }}</td><td class="num">{{ $r['orders'] }}</td><td class="num">{{ $r['conversion'] !== null ? $r['conversion'].'%' : '—' }}</td>
    <td class="num">{{ money($r['sales_total']) }}</td><td class="num">{{ minutes_label($r['avg_to_dispatch']) }}</td><td class="num">{{ minutes_label($r['avg_to_present']) }}</td><td class="num">{{ minutes_label($r['avg_to_close']) }}</td>
    <td class="num">{{ $r['feedback_avg'] ? number_format($r['feedback_avg'], 1, ',', '').' ★ ('.$r['feedback_count'].')' : '—' }}</td><td class="num">{{ $r['actions'] }}</td><td class="num">{{ $r['messages'] }}</td></tr>
  @empty <tr><td colspan="11" class="empty">Sem atendimentos.</td></tr>@endforelse
</table></div>
@elseif($report === 'tempos')
<div class="grid g4">
  <div class="kpi"><div class="l">Cotações</div><div class="v">{{ $times['quotes'] }}</div><div class="s">{{ $times['orders'] }} com pedido · {{ $times['lost'] }} perdidas</div></div>
  <div class="kpi"><div class="l">Pedido do cliente → envio às farmácias</div><div class="v">{{ minutes_label($times['request_to_dispatch']) }}</div></div>
  <div class="kpi"><div class="l">Envio → 1ª resposta</div><div class="v">{{ minutes_label($times['dispatch_to_first_response']) }}</div><div class="s">resposta média por farmácia: {{ minutes_label($times['pharmacy_response']) }}</div></div>
  <div class="kpi"><div class="l">Pedido → cotação apresentada</div><div class="v">{{ minutes_label($times['request_to_present']) }}</div></div>
  <div class="kpi"><div class="l">Pedido → finalização</div><div class="v">{{ minutes_label($times['request_to_close']) }}</div></div>
  <div class="kpi"><div class="l">Avaliação média</div><div class="v">{{ $times['feedback_avg'] ? number_format($times['feedback_avg'], 2, ',', '').' ★' : '—' }}</div><div class="s">{{ $times['feedback_count'] }} avaliação(ões)</div></div>
  <div class="kpi" style="grid-column:span 2"><div class="l">Distribuição das notas</div>
    @foreach(array_reverse($times['feedback_dist'], true) as $n => $q)
      @php($pct = $times['feedback_count'] ? round($q / $times['feedback_count'] * 100) : 0)
      <div class="row" style="gap:6px;align-items:center;flex-wrap:nowrap"><span class="auto stars" style="width:70px">{{ str_repeat('★', $n) }}</span><div style="flex:1;background:#eef2f7;border-radius:4px;height:10px"><div style="width:{{ $pct }}%;background:var(--green);height:10px;border-radius:4px"></div></div><span class="auto small" style="width:60px">{{ $q }} ({{ $pct }}%)</span></div>
    @endforeach
  </div>
</div>
@else
<div class="card table-wrap"><table>
  <tr><th>Data</th><th>Tipo</th><th>Código</th><th>Cliente</th><th>Farmácia</th><th>Situação</th><th class="num">Pedido</th><th class="num">Nota</th><th class="num">Comissão</th></tr>
  @forelse($rows as $r)
  <tr><td class="small nowrap">{{ $r['date']->format('d/m/Y H:i') }}</td><td>{{ $r['type'] }}</td><td>{{ $r['code'] }}</td><td>{{ $r['customer']->displayName() }}</td><td>{{ $r['pharmacy']?->trade_name }}</td>
    <td><span class="badge b-{{ $r['status'] }}">{{ $r['status'] }}</span></td><td class="num">{{ $r['total'] ? money($r['total']) : '—' }}</td><td class="num">{{ $r['receipt_total'] !== null ? money($r['receipt_total']) : '—' }}</td><td class="num">{{ money($r['commission']) }}</td></tr>
  @empty <tr><td colspan="9" class="empty">Sem movimento.</td></tr>@endforelse
  @if($rows->isNotEmpty())<tr><th colspan="6">Total</th><th class="num">{{ money($rows->sum('total')) }}</th><th class="num">{{ money($rows->sum('receipt_total')) }}</th><th class="num">{{ money($rows->where('status','!=','cancelado')->sum('commission')) }}</th></tr>@endif
</table></div>
@endif
@endsection
