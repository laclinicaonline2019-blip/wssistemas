@extends('layouts.app')
@section('title', 'Notas fiscais')
@section('content')
<div class="top"><div><h1>Notas fiscais (captação de vendas)</h1><div class="sub">O cliente envia a foto da nota; a IA lê, o sistema liga ao pedido e você confere. A comissão incide sobre tudo o que foi comprado.</div></div></div>
<div class="tabs">@foreach(['pendente' => 'Para conferir', 'conferida' => 'Conferidas', 'rejeitada' => 'Rejeitadas', 'todas' => 'Todas'] as $k => $v)<a href="{{ route('receipts.index', ['status' => $k]) }}" class="{{ request('status', 'pendente') === $k ? 'on' : '' }}">{{ $v }}</a>@endforeach</div>
<div class="card table-wrap"><table>
  <tr><th>Nota</th><th>Recebida</th><th>Cliente</th><th>Farmácia</th><th>Pedido</th><th class="num">Total</th><th>Situação</th><th></th></tr>
  @forelse($receipts as $r)
  <tr><td><a href="{{ route('receipts.show', $r) }}"><b>{{ $r->code }}</b></a><div class="small muted">{{ $r->source }}</div></td><td class="small">{{ $r->created_at->format('d/m H:i') }}</td>
    <td>{{ $r->customer->displayName() }}</td><td>{{ $r->pharmacy?->trade_name ?? $r->issuer_name ?? '—' }}</td><td>{{ $r->order?->code ?? ($r->source === 'farmacia' ? '💳 cartão (farmácia)' : '💳 cartão, sem cotação') }}</td>
    <td class="num">{{ $r->total ? money($r->total) : '—' }}</td><td><span class="badge b-{{ $r->status }}">{{ $r->statusLabel() }}</span></td>
    <td><a class="btn sm p" href="{{ route('receipts.show', $r) }}">{{ $r->status === 'pendente' ? 'Conferir' : 'Ver' }}</a></td></tr>
  @empty <tr><td colspan="8" class="empty">Nada por aqui.</td></tr>
  @endforelse
</table>{{ $receipts->links() }}</div>
@endsection
