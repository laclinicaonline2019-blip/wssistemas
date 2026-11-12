@extends('layouts.app')
@section('title', 'Cotações')
@section('content')
<div class="top"><div><h1>{{ auth()->user()->pharmacy->trade_name }}</h1><div class="sub">Responda rápido: as farmácias que respondem primeiro e com melhor preço vendem mais.</div></div></div>
<div class="grid g4" style="margin-bottom:16px">
  <div class="kpi"><div class="l">Aguardando sua resposta</div><div class="v" style="color:var(--red)">{{ $pending->count() }}</div></div>
  <div class="kpi"><div class="l">Cotações no mês</div><div class="v">{{ $month['quotes_received'] ?? 0 }}</div><div class="s">respondeu {{ $month['response_rate'] ?? '—' }}% · tempo médio {{ minutes_label($month['avg_response_min'] ?? null) }}</div></div>
  <div class="kpi"><div class="l">Vendas pela Pesquisa Farma no mês</div><div class="v">{{ money($month['orders_total'] ?? 0) }}</div><div class="s">{{ $month['orders'] ?? 0 }} pedido(s)</div></div>
  <div class="kpi"><div class="l">Comissão do mês ({{ number_format(auth()->user()->pharmacy->commission_percent, 1, ',', '') }}%)</div><div class="v">{{ money($month['commission'] ?? 0) }}</div><div class="s">cobrança {{ auth()->user()->pharmacy->commission_cycle }}</div></div>
</div>
<div class="card table-wrap"><h2>⏳ Aguardando resposta</h2><table>
  <tr><th>Cotação</th><th>Itens</th><th>Bairro do cliente</th><th>Recebida</th><th>Prazo</th><th></th></tr>
  @forelse($pending as $d)
  <tr><td><b>{{ $d->quote->code }}</b></td><td class="small">@foreach($d->quote->items as $i){{ $i->label() }}@if(! $loop->last)<br>@endif @endforeach</td>
    <td>{{ $d->quote->neighborhood?->name }} @if($d->distance_km !== null)<span class="small muted">({{ number_format($d->distance_km, 1, ',', '.') }} km)</span>@endif<div class="small">{{ $d->quote->fulfillment === 'entrega' ? '🛵 entrega' : '🏪 retirada' }}</div></td>
    <td class="small">{{ $d->sent_at?->diffForHumans() }}</td><td class="small">{{ $d->quote->response_deadline_at?->format('H:i') }}</td>
    <td><a class="btn g" href="{{ route('panel.quote', $d) }}">Responder</a></td></tr>
  @empty <tr><td colspan="6" class="empty">Nenhuma cotação aguardando. 👍</td></tr>@endforelse
</table></div>
<div class="card table-wrap"><h2>Respondidas recentemente</h2><table>
  <tr><th>Cotação</th><th>Situação</th><th class="num">Seu total</th><th class="num">Sua posição</th><th>Resultado</th><th></th></tr>
  @foreach($recent as $d)
  <tr><td>{{ $d->quote->code }}</td><td><span class="badge b-{{ $d->status }}">{{ $d->statusLabel() }}</span></td><td class="num">{{ $d->total ? money($d->total) : '—' }}</td>
    <td class="num">{{ $d->rank ? $d->rank.'º' : '—' }}</td>
    <td class="small">@if($d->quote->order && $d->quote->order->pharmacy_id === $d->pharmacy_id)<b style="color:var(--green)">✔ Venda para você</b>@elseif(in_array($d->quote->status, ['pedido','finalizada']))Cliente comprou em outra@else{{ $d->quote->statusLabel() }}@endif</td>
    <td>@if($d->quote->isOpen())<a class="btn sm" href="{{ route('panel.quote', $d) }}">Editar</a>@endif</td></tr>
  @endforeach
</table></div>
@endsection
