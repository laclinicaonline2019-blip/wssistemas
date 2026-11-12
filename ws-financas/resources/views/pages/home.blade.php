@extends('layouts.guest')
@section('title', 'WS Finanças em Dia — Seu dinheiro em dia')
@section('tools')<a class="btn" href="{{ route('login') }}">Entrar</a><a class="btn primary" href="{{ route('signup') }}">Teste grátis</a>@endsection
@section('content')
<section class="hero">
  <div style="display:flex;flex-direction:column;gap:12px">
    <h2>Contas, dívidas e caixa das suas empresas em dia — direto do WhatsApp.</h2>
    <p class="muted mt0">Mande “paguei 350 de energia da empresa A” e o lançamento aparece no painel na hora. Veja o que vence amanhã, o plano para sair das dívidas, o fechamento do mês com sugestões de economia e relatórios por empresa ou consolidados.</p>
    <div class="tools"><a class="btn primary" href="{{ route('signup') }}">Começar {{ config('wsf.billing.trial_days') }} dias grátis</a><a class="btn" href="#planos">Ver planos</a></div>
  </div>
  <img src="{{ asset('assets/img/logo.webp') }}" alt="WS Finanças em Dia" width="768" height="512">
</section>
<section class="grid3">
  <div class="card"><h3>Contas do mês</h3><p class="muted mt0">Pagas, a vencer e atrasadas, contas fixas e parceladas, entradas e sobra prevista.</p></div>
  <div class="card"><h3>Assistente no WhatsApp</h3><p class="muted mt0">Lança, consulta, confirma quando tem dúvida, evita repetidos e desfaz com uma palavra.</p></div>
  <div class="card"><h3>Várias empresas</h3><p class="muted mt0">Dados separados por empresa, visão consolidada, usuários com permissões e acesso do contador.</p></div>
</section>
<section class="block" id="planos"><h2>Planos</h2>
  <div class="plans">
    @foreach ($plans as $p)
      <div class="plan {{ $p->code === 'profissional' ? 'featured' : '' }}">
        <h3>{{ $p->name }}</h3>
        <span class="price num">{{ brl($p->price_monthly_cents) }}<span class="small muted">/mês</span></span>
        <span class="small muted">ou {{ brl($p->price_yearly_cents) }}/ano</span>
        <p class="muted small mt0">{{ $p->description }}</p>
        <ul>
          <li>{{ $p->max_companies }} {{ $p->max_companies > 1 ? 'empresas' : 'empresa' }} · {{ $p->max_users }} usuários</li>
          @foreach (\App\Models\Plan::FEATURES as $k => $label) @if ($p->has($k))<li>{{ $label }}</li>@endif @endforeach
          @if ($p->whatsapp_messages_month)<li>{{ number_format($p->whatsapp_messages_month, 0, ',', '.') }} mensagens/mês no WhatsApp</li>@endif
        </ul>
        <a class="btn {{ $p->code === 'profissional' ? 'primary' : '' }}" href="{{ route('signup', ['plano' => $p->code]) }}">Testar grátis</a>
      </div>
    @endforeach
  </div>
</section>
@endsection
