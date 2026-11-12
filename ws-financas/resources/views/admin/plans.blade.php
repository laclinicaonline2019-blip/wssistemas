@extends('layouts.admin')
@section('title', 'Planos e preços')
@section('content')
<p class="small muted">Preços, limites e recursos são editáveis. Assinaturas existentes mantêm o preço contratado até trocarem de plano.</p>
@foreach ($plans->concat([null]) as $p)
<form class="card" method="post" action="{{ $p ? route('admin.plans.update', $p) : route('admin.plans.store') }}">@csrf @if($p) @method('PUT') @endif
  <div class="blockhead"><h3>{{ $p ? $p->name : 'Novo plano' }} @if($p)<span class="small muted">código {{ $p->code }} · {{ $p->subscriptions_count }} assinante(s)</span>@endif</h3></div>
  <div class="form">
    @unless($p)<div class="field"><label>Código</label><input name="code" required pattern="[a-z0-9_-]+"></div>@endunless
    <div class="field"><label>Nome</label><input name="name" required value="{{ $p?->name }}"></div>
    <div class="field full"><label>Descrição</label><input name="description" value="{{ $p?->description }}"></div>
    <div class="field"><label>Preço mensal (R$)</label><input name="price_monthly" required value="{{ $p ? number_format($p->price_monthly_cents/100, 2, ',', '.') : '' }}"></div>
    <div class="field"><label>Preço anual (R$)</label><input name="price_yearly" required value="{{ $p ? number_format($p->price_yearly_cents/100, 2, ',', '.') : '' }}"></div>
    <div class="field"><label>Máx. empresas</label><input type="number" name="max_companies" min="1" required value="{{ $p?->max_companies ?? 1 }}"></div>
    <div class="field"><label>Máx. usuários</label><input type="number" name="max_users" min="1" required value="{{ $p?->max_users ?? 1 }}"></div>
    <div class="field"><label>Mensagens WhatsApp/mês</label><input type="number" name="whatsapp_messages_month" min="0" required value="{{ $p?->whatsapp_messages_month ?? 0 }}"></div>
    <div class="field"><label>Usos de IA/mês</label><input type="number" name="ai_calls_month" min="0" required value="{{ $p?->ai_calls_month ?? 0 }}"></div>
    <div class="field"><label>Ordem</label><input type="number" name="sort" value="{{ $p?->sort ?? 9 }}"></div>
    <div class="checks full">@foreach (\App\Models\Plan::FEATURES as $k => $l)<label><input type="checkbox" name="features[]" value="{{ $k }}" @checked($p?->has($k))> {{ $l }}</label>@endforeach
      <label><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked($p?->active ?? true)> Ativo (à venda)</label></div>
  </div>
  <div class="formacts"><button class="btn primary" type="submit">{{ $p ? 'Salvar' : 'Criar plano' }}</button></div>
</form>
@endforeach
@endsection
