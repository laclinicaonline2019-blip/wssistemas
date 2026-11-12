@extends('layouts.app')
@section('title', $customer->displayName())
@section('content')
<div class="top">
  <div><h1>{{ $customer->displayName() }}</h1><div class="sub">{{ \App\Support\Text::formatPhone($customer->phone) }} · {{ $customer->fullAddress() ?: 'sem endereço' }} · cliente desde {{ $customer->created_at->format('d/m/Y') }}</div></div>
  <div class="row auto"><a class="btn" href="{{ route('inbox.show', $customer) }}">💬 Conversa</a><a class="btn" href="{{ route('customers.edit', $customer) }}">Editar</a><a class="btn p" href="{{ route('quotes.create', ['cliente' => $customer->id]) }}">+ Cotação</a></div>
</div>
<div class="grid g4" style="margin-bottom:16px">
  <div class="kpi"><div class="l">Cotações</div><div class="v">{{ $quotes->count() }}</div></div>
  <div class="kpi"><div class="l">Pedidos</div><div class="v">{{ money($stats['orders_total']) }}</div><div class="s">{{ $orders->count() }} pedido(s)</div></div>
  <div class="kpi"><div class="l">Compras com nota</div><div class="v">{{ money($stats['receipts_total']) }}</div><div class="s">{{ $receipts->where('status','conferida')->count() }} nota(s) conferida(s)</div></div>
  <div class="kpi"><div class="l">Pontos</div><div class="v">{{ $customer->points_balance }}</div><div class="s">avaliação média {{ $stats['feedback'] ? number_format($stats['feedback'], 1, ',', '') : '—' }}</div></div>
</div>
<div class="grid g2">
  <div class="card table-wrap"><h3>Cotações</h3><table>
    @forelse($quotes as $q)<tr><td><a href="{{ route('quotes.show', $q) }}">{{ $q->code }}</a></td><td class="small">{{ $q->requested_at?->format('d/m/Y') }}</td><td><span class="badge b-{{ $q->status }}">{{ $q->statusLabel() }}</span></td><td class="num">{{ $q->order ? money($q->order->total) : '' }}</td></tr>
    @empty<tr><td class="empty">Nenhuma.</td></tr>@endforelse</table></div>
  <div class="card table-wrap"><h3>Notas fiscais</h3>
    <form method="post" action="{{ route('receipts.store') }}" enctype="multipart/form-data" class="row" style="margin-bottom:8px">@csrf<input type="hidden" name="customer_id" value="{{ $customer->id }}"><input type="file" name="file" accept="image/*,application/pdf" required><button class="btn sm auto">Enviar nota</button></form>
    <table>@forelse($receipts as $r)<tr><td><a href="{{ route('receipts.show', $r) }}">{{ $r->code }}</a></td><td>{{ $r->pharmacy?->trade_name }}</td><td><span class="badge b-{{ $r->status }}">{{ $r->statusLabel() }}</span></td><td class="num">{{ $r->total ? money($r->total) : '' }}</td></tr>
    @empty<tr><td class="empty">Nenhuma.</td></tr>@endforelse</table></div>
</div>
<div class="grid g2">
  <div class="card table-wrap"><h3>Extrato de pontos</h3>
    <form method="post" action="{{ route('customers.points', $customer) }}" class="row" style="margin-bottom:8px">@csrf<input name="points" type="number" placeholder="+/- pontos" required><input name="reason" placeholder="Motivo" required><button class="btn sm auto">Ajustar</button></form>
    <table>@forelse($points as $p)<tr><td class="small">{{ $p->created_at->format('d/m/Y') }}</td><td>{{ $p->reason }}</td><td class="num" style="color:{{ $p->points < 0 ? 'var(--red)' : 'var(--green)' }}">{{ $p->points > 0 ? '+' : '' }}{{ $p->points }}</td></tr>
    @empty<tr><td class="empty">Sem pontos.</td></tr>@endforelse</table></div>
  <div class="card table-wrap"><h3>Brindes</h3>
    <form method="post" action="{{ route('loyalty.redeem', $customer) }}" class="row" style="margin-bottom:8px">@csrf<select name="reward_id">@foreach($rewards as $rw)<option value="{{ $rw->id }}">{{ $rw->name }} — {{ $rw->points_cost }} pts</option>@endforeach</select><button class="btn sm auto">Trocar pontos</button></form>
    <table>@forelse($redemptions as $rd)<tr><td>{{ $rd->reward->name }}</td><td class="num">{{ $rd->points }} pts</td><td><span class="badge b-{{ $rd->status }}">{{ \App\Models\Redemption::STATUSES[$rd->status] }}</span></td></tr>
    @empty<tr><td class="empty">Nenhuma troca.</td></tr>@endforelse</table></div>
</div>
@endsection
