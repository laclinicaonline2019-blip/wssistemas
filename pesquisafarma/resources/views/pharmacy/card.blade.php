@extends('layouts.app')
@section('title', 'Cartão fidelidade')
@section('content')
<div class="top"><div><h1>💳 Cartão fidelidade no balcão</h1><div class="sub">Cliente Pesquisa Farma comprando direto na loja, sem cotação: confira o cartão e registre a venda. A venda entra na comissão da Pesquisa Farma e o cliente ganha pontos.</div></div></div>
<div class="card">
  <form class="row" method="get">
    <div class="f"><label>Nº do cartão (ex.: PF 000123-4) ou celular completo do cliente</label><input name="cartao" value="{{ $q }}" autofocus required></div>
    <button class="btn p auto">Consultar</button>
  </form>
  @if($q !== '' && ! $customer)<div class="alert err">Cartão não encontrado. Peça ao cliente para mandar um "oi" no WhatsApp da Pesquisa Farma para se cadastrar.</div>@endif
  @if($customer)
  <div class="grid g2" style="margin-top:10px;align-items:start">
    <div>
      <div class="alert err small">💡 Oriente o cliente: <b>pedindo a cotação pelo WhatsApp da Pesquisa Farma antes de comprar</b>, ele ganha {{ strip_tags(str_replace('*', '', \App\Services\LoyaltyService::quoteIncentive())) }}. Compra direta no balcão rende menos pontos.</div>
      <div class="alert ok">✔ Cartão válido — <b>{{ explode(' ', trim((string) $customer->name))[0] }}</b> · Nº {{ \App\Services\LoyaltyService::cardNumber($customer) }} · celular final {{ substr($customer->phone, -4) }}</div>
    </div>
    <form method="post" action="{{ route('panel.card.sale', $customer) }}" enctype="multipart/form-data">@csrf
      <h3>Registrar a venda</h3>
      <div class="row"><div class="f"><label>Valor total pago (R$) *</label><input name="total" inputmode="decimal" required></div>
        <div class="f"><label>Nº da nota/cupom</label><input name="nf_number"></div></div>
      <div class="row"><div class="f"><label>Data</label><input type="date" name="purchase_date" value="{{ today()->toDateString() }}"></div>
        <div class="f"><label>Foto da nota (opcional)</label><input type="file" name="file" accept="image/*,application/pdf"></div></div>
      <button class="btn g">Registrar venda do cartão</button>
    </form>
  </div>
  @endif
</div>
<div class="card table-wrap"><h2>Vendas do cartão registradas por você</h2><table>
  <tr><th>Data</th><th>Registro</th><th>Cliente</th><th class="num">Valor</th><th>Situação</th></tr>
  @forelse($recent as $r)<tr><td class="small">{{ $r->created_at->format('d/m/Y H:i') }}</td><td>{{ $r->code }}</td><td>{{ explode(' ', trim((string) $r->customer->name))[0] }} · final {{ substr($r->customer->phone, -4) }}</td><td class="num">{{ money($r->total) }}</td><td><span class="badge b-{{ $r->status }}">{{ $r->statusLabel() }}</span></td></tr>
  @empty<tr><td colspan="5" class="empty">Nenhuma venda do cartão registrada ainda.</td></tr>@endforelse
</table></div>
@endsection
