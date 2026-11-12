@extends('layouts.app')
@section('title', $pharmacy->exists ? 'Editar farmácia' : 'Nova farmácia')
@section('content')
<div class="top"><h1>{{ $pharmacy->exists ? 'Editar '.$pharmacy->trade_name : 'Nova farmácia' }}</h1></div>
<form method="post" action="{{ $pharmacy->exists ? route('pharmacies.update', $pharmacy) : route('pharmacies.store') }}">@csrf @if($pharmacy->exists) @method('PUT') @endif
<div class="card"><h2>Dados</h2>
  <div class="row"><div class="f"><label>Nome fantasia *</label><input name="trade_name" value="{{ old('trade_name', $pharmacy->trade_name) }}" required></div>
    <div class="f"><label>Razão social</label><input name="legal_name" value="{{ old('legal_name', $pharmacy->legal_name) }}"></div>
    <div class="f"><label>CNPJ (usado para reconhecer as notas fiscais)</label><input name="cnpj" value="{{ old('cnpj', $pharmacy->cnpj) }}"></div></div>
  <div class="row"><div class="f"><label>Telefone</label><input name="phone" value="{{ old('phone', $pharmacy->phone) }}"></div>
    <div class="f"><label>WhatsApp (recebe as cotações)</label><input name="whatsapp" value="{{ old('whatsapp', $pharmacy->whatsapp) }}"></div>
    <div class="f"><label>E-mail</label><input name="email" value="{{ old('email', $pharmacy->email) }}"></div>
    <div class="f"><label>Responsável</label><input name="responsible" value="{{ old('responsible', $pharmacy->responsible) }}"></div></div>
  <div class="row"><div class="f"><label>CEP</label><input name="cep" value="{{ old('cep', $pharmacy->cep) }}"></div>
    <div class="f" style="flex:3"><label>Endereço</label><input name="address" value="{{ old('address', $pharmacy->address) }}"></div>
    <div class="f"><label>Número</label><input name="number" value="{{ old('number', $pharmacy->number) }}"></div></div>
  @include('partials.location', ['cityId' => old('city_id', $pharmacy->city_id), 'neighborhoodId' => old('neighborhood_id', $pharmacy->neighborhood_id), 'required' => true])
  <div class="row">    <div class="f"><label>Latitude</label><input name="lat" value="{{ old('lat', $pharmacy->lat) }}" placeholder="-23.55"></div>
    <div class="f"><label>Longitude</label><input name="lng" value="{{ old('lng', $pharmacy->lng) }}" placeholder="-46.63"></div></div>
  <p class="small muted">Dica: no Google Maps, clique com o botão direito sobre a farmácia para copiar a latitude e longitude. Sem coordenadas, a distância usa o bairro.</p>
  <div class="row"><div class="f"><label>Horário de funcionamento</label><input name="opening_hours" value="{{ old('opening_hours', $pharmacy->opening_hours) }}"></div>
    <div class="f"><label>Formas de pagamento</label><input name="payment_methods" value="{{ old('payment_methods', $pharmacy->payment_methods) }}"></div></div>
</div>
<div class="grid g2">
<div class="card"><h2>💰 Regra comercial</h2>
  <div class="row"><div class="f"><label>% sobre as vendas (serviço de cotação) *</label><input name="commission_percent" type="number" step="0.01" min="0" max="100" value="{{ old('commission_percent', $pharmacy->commission_percent) }}" required></div>
    <div class="f"><label>Cobrança</label><select name="commission_cycle">@foreach(\App\Models\Pharmacy::CYCLES as $k => $v)<option value="{{ $k }}" @selected(old('commission_cycle', $pharmacy->commission_cycle) === $k)>{{ $v }}</option>@endforeach</select></div></div>
  <p class="small muted">A comissão incide sobre tudo o que o cliente comprou: o total da nota fiscal (quando enviada) ou o total do pedido.</p>
  <label class="check"><input type="checkbox" name="active" value="1" @checked(old('active', $pharmacy->active))> Farmácia ativa (recebe cotações)</label>
</div>
<div class="card"><h2>🛵 Entrega</h2>
  <label class="check"><input type="checkbox" name="delivers" value="1" @checked(old('delivers', $pharmacy->delivers))> Faz entrega</label>
  <div class="row"><div class="f"><label>Raio de entrega (km)</label><input name="delivery_radius_km" type="number" step="0.1" value="{{ old('delivery_radius_km', $pharmacy->delivery_radius_km) }}"></div>
    <div class="f"><label>Taxa base (R$)</label><input name="delivery_base_fee" value="{{ old('delivery_base_fee', $pharmacy->delivery_base_fee) }}"></div>
    <div class="f"><label>+ por km (R$)</label><input name="delivery_fee_per_km" value="{{ old('delivery_fee_per_km', $pharmacy->delivery_fee_per_km) }}"></div></div>
  <div class="row"><div class="f"><label>Grátis acima de (R$)</label><input name="free_delivery_above" value="{{ old('free_delivery_above', $pharmacy->free_delivery_above) }}"></div>
    <div class="f"><label>Prazo médio (min)</label><input name="delivery_eta_min" type="number" value="{{ old('delivery_eta_min', $pharmacy->delivery_eta_min) }}"></div></div>
  <p class="small muted">Taxas específicas por bairro podem ser definidas na ficha da farmácia (zonas de entrega).</p>
</div>
</div>
<div class="card"><div class="f"><label>Observações</label><textarea name="notes" rows="2">{{ old('notes', $pharmacy->notes) }}</textarea></div><button class="btn p lg">Salvar</button></div>
</form>
@endsection
