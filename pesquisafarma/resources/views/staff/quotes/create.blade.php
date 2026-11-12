@extends('layouts.app')
@section('title', 'Nova cotação')
@section('content')
<div class="top"><div><h1>Nova cotação</h1><div class="sub">Para atendimentos por telefone, balcão ou WhatsApp. A IA separa os itens do texto ou da foto.</div></div></div>
<div class="card">
<form method="post" action="{{ route('quotes.store') }}" enctype="multipart/form-data">@csrf
  @if($customer)
    <input type="hidden" name="customer_id" value="{{ $customer->id }}">
    <p>Cliente: <b>{{ $customer->displayName() }}</b> · {{ \App\Support\Text::formatPhone($customer->phone) }} · {{ $customer->neighborhood?->name }}</p>
  @else
  <div class="row">
    <div class="f"><label>Telefone/WhatsApp do cliente *</label><input name="phone" value="{{ old('phone') }}" placeholder="(11) 99999-0000" required></div>
    <div class="f"><label>Nome (se for novo)</label><input name="name" value="{{ old('name') }}"></div>
    <div class="f"><label>Bairro</label><select name="neighborhood_id"><option value="">—</option>@include('partials.neighborhood-options')</select></div>
  </div>
  <p class="small muted">Se o telefone já existir na base, a cotação vai para o cadastro existente.</p>
  @endif
  <div class="row">
    <div class="f"><label>Canal</label><select name="channel"><option value="telefone">Telefone</option><option value="whatsapp">WhatsApp</option><option value="balcao">Balcão</option></select></div>
    <div class="f"><label>Foto da receita / lista (opcional)</label><input type="file" name="file" accept="image/*,application/pdf"></div>
  </div>
  <div class="f"><label>Medicamentos pedidos (um por linha, ex.: "2x Losartana 50mg genérico")</label><textarea name="text" rows="6">{{ old('text') }}</textarea></div>
  <button class="btn p lg">Criar cotação</button>
</form>
</div>
@endsection
