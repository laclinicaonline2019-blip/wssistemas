@extends('layouts.app')
@section('title', 'Promoções')
@section('content')
<div class="top"><div><h1>Promoções do dia</h1><div class="sub">Farmácias enviam pelo painel; você aprova e divulga para {{ $audience }} cliente(s) que aceitam ofertas.</div></div></div>
<details class="card"><summary>+ Cadastrar promoção</summary>
  <form method="post" action="{{ route('promotions.store') }}" style="margin-top:10px">@csrf
    <div class="row"><div class="f"><label>Farmácia</label><select name="pharmacy_id"><option value="">Pesquisa Farma (geral)</option>@foreach($pharmacies as $p)<option value="{{ $p->id }}">{{ $p->trade_name }}</option>@endforeach</select></div>
      <div class="f"><label>Título *</label><input name="title" required></div><div class="f"><label>Produto</label><input name="product_name"></div></div>
    <div class="row"><div class="f"><label>De (R$)</label><input name="original_price"></div><div class="f"><label>Por (R$)</label><input name="price"></div>
      <div class="f"><label>Início</label><input type="date" name="valid_from" value="{{ today()->toDateString() }}"></div><div class="f"><label>Válida até</label><input type="date" name="valid_until" value="{{ today()->toDateString() }}"></div></div>
    <div class="f"><label>Descrição</label><textarea name="description" rows="2"></textarea></div><button class="btn p">Salvar</button>
  </form>
</details>
<div class="tabs"><a href="{{ route('promotions.index') }}" class="{{ request('status') ? '' : 'on' }}">Todas</a>@foreach(\App\Models\Promotion::STATUSES as $k => $v)<a href="{{ route('promotions.index', ['status' => $k]) }}" class="{{ request('status') === $k ? 'on' : '' }}">{{ $v }}</a>@endforeach</div>
<div class="card table-wrap"><table>
  <tr><th>Promoção</th><th>Farmácia</th><th class="num">Preço</th><th>Validade</th><th>Situação</th><th class="num">Enviada p/</th><th></th></tr>
  @forelse($promotions as $p)
  <tr><td><b>{{ $p->title }}</b><div class="small">{{ $p->product_name }}</div><div class="small muted">{{ $p->description }}</div></td><td>{{ $p->pharmacy?->trade_name ?? 'Geral' }}</td>
    <td class="num">@if($p->original_price)<s class="muted">{{ money($p->original_price) }}</s><br>@endif{{ $p->price ? money($p->price) : '' }}</td>
    <td class="small">{{ $p->valid_from?->format('d/m') }} – {{ $p->valid_until?->format('d/m/Y') }}</td>
    <td><span class="badge b-{{ $p->status }}">{{ \App\Models\Promotion::STATUSES[$p->status] }}</span></td><td class="num">{{ $p->broadcast_count }}</td>
    <td class="nowrap">
      @if($p->status === 'pendente')
        <form method="post" action="{{ route('promotions.status', $p) }}" style="display:inline">@csrf<input type="hidden" name="status" value="aprovada"><button class="btn sm g">Aprovar</button></form>
        <form method="post" action="{{ route('promotions.status', $p) }}" style="display:inline">@csrf<input type="hidden" name="status" value="recusada"><button class="btn sm r">Recusar</button></form>
      @elseif(in_array($p->status, ['aprovada','divulgada']))
        <form method="post" action="{{ route('promotions.broadcast', $p) }}" class="row" style="gap:4px">@csrf<select name="scope" class="auto"><option value="todos">Toda a lista</option><option value="bairro">Mesmo bairro</option><option value="proximos">Clientes próximos</option></select>
          <button class="btn sm p auto" data-confirm="Enviar esta promoção pelo WhatsApp?">📣 Divulgar</button></form>
      @endif
    </td></tr>
  @empty <tr><td colspan="7" class="empty">Nenhuma promoção.</td></tr>
  @endforelse
</table>{{ $promotions->links() }}</div>
@endsection
