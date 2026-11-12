@extends('layouts.app')
@section('title', $customer->exists ? 'Editar cliente' : 'Novo cliente')
@section('content')
<div class="top"><h1>{{ $customer->exists ? 'Editar '.$customer->displayName() : 'Novo cliente' }}</h1></div>
<div class="card">
<form method="post" action="{{ $customer->exists ? route('customers.update', $customer) : route('customers.store') }}">@csrf @if($customer->exists) @method('PUT') @endif
  <div class="row">
    <div class="f"><label>Nome completo *</label><input name="name" value="{{ old('name', $customer->name) }}" required></div>
    <div class="f"><label>WhatsApp *</label><input name="phone" value="{{ old('phone', $customer->phone ? \App\Support\Text::formatPhone($customer->phone) : '') }}" required></div>
    <div class="f"><label>CPF</label><input name="cpf" value="{{ old('cpf', $customer->cpf) }}"></div>
  </div>
  <div class="row">
    <div class="f"><label>E-mail</label><input name="email" type="email" value="{{ old('email', $customer->email) }}"></div>
    <div class="f"><label>Nascimento</label><input name="birth_date" type="date" value="{{ old('birth_date', $customer->birth_date?->toDateString()) }}"></div>
    <div class="f"><label>CEP</label><input name="cep" value="{{ old('cep', $customer->cep) }}"></div>
  </div>
  <div class="row">
    <div class="f" style="flex:3"><label>Endereço</label><input name="address" value="{{ old('address', $customer->address) }}"></div>
    <div class="f"><label>Número</label><input name="number" value="{{ old('number', $customer->number) }}"></div>
    <div class="f"><label>Complemento</label><input name="complement" value="{{ old('complement', $customer->complement) }}"></div>
  </div>
  <div class="row">
    <div class="f"><label>Bairro</label><select name="neighborhood_id"><option value="">—</option>@foreach($neighborhoods as $n)<option value="{{ $n->id }}" @selected(old('neighborhood_id', $customer->neighborhood_id) == $n->id)>{{ $n->label() }}</option>@endforeach</select></div>
    <div class="f"><label>Cidade</label><input name="city" value="{{ old('city', $customer->city) }}"></div>
  </div>
  <div class="f"><label>Observações</label><textarea name="notes" rows="2">{{ old('notes', $customer->notes) }}</textarea></div>
  <div class="row auto">
    <label class="check"><input type="checkbox" name="accepts_marketing" value="1" @checked(old('accepts_marketing', $customer->accepts_marketing))> Aceita receber promoções</label>
    <label class="check"><input type="checkbox" name="loyalty_enabled" value="1" @checked(old('loyalty_enabled', $customer->exists ? $customer->loyalty_enabled : true))> Participa do programa de pontos</label>
    <label class="check"><input type="checkbox" name="lgpd" value="1" @checked($customer->lgpd_consent_at)> Consentimento LGPD {{ $customer->lgpd_consent_at ? '('.$customer->lgpd_consent_at->format('d/m/Y').')' : '' }}</label>
    @unless($customer->isRegistered())<label class="check"><input type="checkbox" name="finish_registration" value="1" checked> Concluir cadastro do WhatsApp</label>@endunless
  </div>
  <button class="btn p" style="margin-top:10px">Salvar</button>
</form>
</div>
@endsection
