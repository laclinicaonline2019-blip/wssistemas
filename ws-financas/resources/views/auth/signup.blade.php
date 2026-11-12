@extends('layouts.guest')
@section('title', 'Criar conta — WS Finanças em Dia')
@section('tools')<a class="btn" href="{{ route('login') }}">Já tenho conta</a>@endsection
@section('content')
<form class="card" method="post" action="{{ route('signup') }}">@csrf
  <h2>Comece seu teste grátis de {{ config('wsf.billing.trial_days') }} dias</h2>
  <p class="muted mt0">Sem cartão agora. Você escolhe como pagar (Pix, boleto ou cartão) só se decidir continuar.</p>
  <div class="form">
    <div class="field"><label for="person_type">Você é</label><select id="person_type" name="person_type"><option value="pf" @selected(old('person_type')==='pf')>Pessoa física</option><option value="pj" @selected(old('person_type','pj')==='pj')>Empresa (CNPJ)</option></select></div>
    <div class="field"><label for="document">CPF ou CNPJ <span class="muted">(opcional agora)</span></label><input id="document" name="document" value="{{ old('document') }}" inputmode="numeric"></div>
    <div class="field full"><label for="name">Nome completo ou razão social</label><input id="name" name="name" required value="{{ old('name') }}"></div>
    <div class="field full"><label for="company_name">Nome da primeira empresa <span class="muted">(ou deixe em branco para finanças pessoais)</span></label><input id="company_name" name="company_name" value="{{ old('company_name') }}"></div>
    <div class="field"><label for="admin_name">Seu nome</label><input id="admin_name" name="admin_name" required value="{{ old('admin_name') }}" autocomplete="name"></div>
    <div class="field"><label for="phone">Celular (WhatsApp)</label><input id="phone" name="phone" value="{{ old('phone') }}" inputmode="tel" autocomplete="tel"></div>
    <div class="field full"><label for="email">E-mail (será seu login)</label><input id="email" name="email" type="email" required value="{{ old('email') }}" autocomplete="email"></div>
    <div class="field"><label for="password">Senha</label><input id="password" name="password" type="password" required autocomplete="new-password"></div>
    <div class="field"><label for="password_confirmation">Repita a senha</label><input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"></div>
    <p class="full small muted mt0">Senha com no mínimo 10 caracteres, letras maiúsculas e minúsculas, números e símbolos.</p>
    <div class="field"><label for="plan">Plano</label><select id="plan" name="plan">@foreach ($plans as $p)<option value="{{ $p->code }}" @selected(old('plan', $selected ?? 'profissional') === $p->code)>{{ $p->name }} — {{ brl($p->price_monthly_cents) }}/mês</option>@endforeach</select></div>
    <div class="field"><label for="cycle">Cobrança</label><select id="cycle" name="cycle"><option value="monthly">Mensal</option><option value="yearly" @selected(old('cycle')==='yearly')>Anual (desconto)</option></select></div>
    <label class="inline-check full"><input type="checkbox" name="accept" value="1" required> Li e aceito os <a href="{{ route('terms') }}" target="_blank" rel="noopener">termos de uso</a> e a <a href="{{ route('privacy') }}" target="_blank" rel="noopener">política de privacidade</a>.</label>
  </div>
  <div class="formacts"><button class="btn primary" type="submit">Criar minha conta</button></div>
</form>
@endsection
