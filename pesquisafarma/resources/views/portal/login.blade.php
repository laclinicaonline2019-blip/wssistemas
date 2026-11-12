@extends('layouts.guest')
@section('title', 'Área do cliente · Pesquisa Farma')
@section('content')
<div class="card">
  <h2>Área do cliente</h2>
  <p class="small muted">Veja seus pontos, troque por brindes, envie notas fiscais e acompanhe suas cotações.</p>
  @if(! session('code_sent'))
  <form method="post" action="{{ route('portal.code') }}">@csrf
    <div class="f"><label>Seu WhatsApp</label><input name="phone" inputmode="tel" placeholder="(11) 99999-0000" required autofocus></div>
    <button class="btn p lg" style="width:100%;justify-content:center">Receber código no WhatsApp</button>
  </form>
  @else
  <form method="post" action="{{ route('portal.verify') }}">@csrf
    <div class="f"><label>Código de 6 dígitos</label><input name="code" inputmode="numeric" maxlength="6" required autofocus></div>
    <button class="btn g lg" style="width:100%;justify-content:center">Entrar</button>
  </form>
  <p class="small"><a href="{{ route('portal.login') }}">Usar outro número</a></p>
  @endif
</div>
<p class="small muted" style="text-align:center">Ainda não é cliente? Mande um "oi" no nosso WhatsApp. 💬</p>
@endsection
