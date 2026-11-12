@extends('layouts.guest')
@section('title', 'Minha área · Pesquisa Farma')
@section('box', 'wide')
@section('content')
<div class="top"><div><h1>Olá, {{ explode(' ', (string) $customer->name)[0] }}! 👋</h1><div class="sub">{{ $customer->neighborhood?->name }}</div></div>
  <form method="post" action="{{ route('portal.logout') }}">@csrf<button class="btn sm">Sair</button></form></div>
<div class="grid g3" style="margin-bottom:16px">
  @if($loyalty)<div class="kpi"><div class="l">Seus pontos</div><div class="v" style="color:var(--green)">{{ $customer->points_balance }}</div><div class="s">troque por brindes abaixo</div></div>@endif
  <div class="kpi"><div class="l">Cotações</div><div class="v">{{ $customer->quotes()->count() }}</div></div>
  <div class="kpi"><div class="l">Compras registradas</div><div class="v">{{ $customer->receipts()->where('status','conferida')->count() }}</div><div class="s">notas fiscais conferidas</div></div>
</div>
<div class="card"><h2>📄 Enviar nota fiscal da compra</h2>
  <p class="small muted">Envie a foto da nota/cupom: você ganha pontos e participa da premiação do mês.</p>
  <form method="post" action="{{ route('portal.receipt') }}" enctype="multipart/form-data" class="row">@csrf<input type="file" name="file" accept="image/*,application/pdf" capture="environment" required><button class="btn g auto">Enviar</button></form></div>
@if($loyalty && $rewards->isNotEmpty())
<div class="card"><h2>🎁 Brindes</h2><div class="grid g3">
  @foreach($rewards as $rw)<div class="kpi"><b>{{ $rw->name }}</b><div class="small muted">{{ $rw->description }}</div><div class="v" style="font-size:18px">{{ $rw->points_cost }} pts</div>
    @if($rw->min_purchases)<div class="s">exige {{ $rw->min_purchases }} compra(s)</div>@endif
    <form method="post" action="{{ route('portal.redeem', $rw) }}">@csrf<button class="btn sm {{ $customer->points_balance >= $rw->points_cost ? 'p' : '' }}" @disabled($customer->points_balance < $rw->points_cost || ($rw->stock !== null && $rw->stock <= 0)) data-confirm="Trocar {{ $rw->points_cost }} pontos por {{ $rw->name }}?">Trocar</button></form></div>@endforeach
</div></div>
@endif
@if($promotions->isNotEmpty())
<div class="card"><h2>🔥 Promoções do dia</h2><div class="grid g3">
  @foreach($promotions as $p)<div class="kpi"><b>{{ $p->title }}</b><div class="small">{{ $p->product_name }}</div>
    <div>@if($p->original_price)<s class="muted small">{{ money($p->original_price) }}</s> @endif<span class="price">{{ $p->price ? money($p->price) : '' }}</span></div>
    <div class="small muted">{{ $p->pharmacy?->trade_name }} {{ $p->pharmacy?->neighborhood ? '· '.$p->pharmacy->neighborhood->name : '' }}</div></div>@endforeach
</div></div>
@endif
<div class="grid g2">
  <div class="card table-wrap"><h2>Minhas cotações</h2><table>
    @forelse($quotes as $q)<tr><td>{{ $q->code }}<div class="small muted">{{ $q->requested_at?->format('d/m/Y') }}</div></td><td><span class="badge b-{{ $q->status }}">{{ $q->statusLabel() }}</span></td>
      <td class="small">{{ $q->order ? $q->order->pharmacy->trade_name.' · '.money($q->order->total) : '' }}</td>
      <td>@if(in_array($q->status, ['finalizada','pedido']) && ! $q->feedback_rating)<form method="post" action="{{ route('portal.feedback', $q) }}" class="row" style="gap:4px;flex-wrap:nowrap">@csrf<select name="rating" class="auto">@for($i=5;$i>=1;$i--)<option value="{{ $i }}">{{ $i }}★</option>@endfor</select><button class="btn sm auto">Avaliar</button></form>
        @elseif($q->feedback_rating)<span class="stars">{{ str_repeat('★', $q->feedback_rating) }}</span>@endif</td></tr>
    @empty<tr><td class="empty">Nenhuma cotação.</td></tr>@endforelse</table></div>
  <div class="card table-wrap"><h2>Notas e pontos</h2><table>
    @foreach($receipts as $r)<tr><td>{{ $r->code }}<div class="small muted">{{ $r->pharmacy?->trade_name }}</div></td><td><span class="badge b-{{ $r->status }}">{{ $r->statusLabel() }}</span></td><td class="num">{{ $r->total ? money($r->total) : '' }}</td><td class="num small">{{ $r->points_awarded ? '+'.$r->points_awarded.' pts' : '' }}</td></tr>@endforeach
    @foreach($redemptions as $rd)<tr><td>🎁 {{ $rd->reward->name }}</td><td><span class="badge b-{{ $rd->status }}">{{ \App\Models\Redemption::STATUSES[$rd->status] }}</span></td><td></td><td class="num small">-{{ $rd->points }} pts</td></tr>@endforeach
  </table></div>
</div>
@endsection
