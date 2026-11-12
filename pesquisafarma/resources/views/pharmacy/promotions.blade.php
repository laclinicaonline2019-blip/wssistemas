@extends('layouts.app')
@section('title', 'Promoções do dia')
@section('content')
<div class="top"><div><h1>Promoções do dia</h1><div class="sub">Envie suas ofertas: a Pesquisa Farma aprova e divulga para a lista de clientes pelo WhatsApp.</div></div></div>
<div class="card"><form method="post" action="{{ route('panel.promotions.store') }}">@csrf
  <div class="row"><div class="f"><label>Título *</label><input name="title" required placeholder="Ex.: Protetor solar com 30% OFF"></div><div class="f"><label>Produto</label><input name="product_name"></div></div>
  <div class="row"><div class="f"><label>De (R$)</label><input name="original_price"></div><div class="f"><label>Por (R$)</label><input name="price"></div>
    <div class="f"><label>Início</label><input type="date" name="valid_from" value="{{ today()->toDateString() }}"></div><div class="f"><label>Válida até</label><input type="date" name="valid_until" value="{{ today()->toDateString() }}"></div></div>
  <div class="f"><label>Descrição</label><textarea name="description" rows="2"></textarea></div><button class="btn p">Enviar para aprovação</button>
</form></div>
<div class="card table-wrap"><table>
  <tr><th>Promoção</th><th class="num">Preço</th><th>Validade</th><th>Situação</th><th class="num">Clientes alcançados</th></tr>
  @forelse($promotions as $p)<tr><td><b>{{ $p->title }}</b><div class="small">{{ $p->product_name }}</div></td><td class="num">{{ $p->price ? money($p->price) : '' }}</td>
    <td class="small">{{ $p->valid_from?->format('d/m') }} – {{ $p->valid_until?->format('d/m/Y') }}</td><td><span class="badge b-{{ $p->status }}">{{ \App\Models\Promotion::STATUSES[$p->status] }}</span></td><td class="num">{{ $p->broadcast_count }}</td></tr>
  @empty <tr><td colspan="5" class="empty">Nenhuma promoção enviada.</td></tr>@endforelse
</table>{{ $promotions->links() }}</div>
@endsection
