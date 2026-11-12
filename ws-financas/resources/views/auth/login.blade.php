@extends('layouts.guest')
@section('title', 'Entrar — WS Finanças em Dia')
@section('tools')<a class="btn" href="{{ route('signup') }}">Criar conta</a>@endsection
@section('content')
<div class="auth" style="display:flex;flex-direction:column;gap:16px">
  <img class="logo" src="{{ asset('assets/img/logo.webp') }}" alt="WS Finanças em Dia — Seu dinheiro em dia." width="768" height="512">
  <form class="card" method="post" action="{{ route('login') }}">@csrf
    <h2>Entrar</h2>
    <div class="field"><label for="email">E-mail</label><input id="email" name="email" type="email" autocomplete="username" required value="{{ old('email') }}" autofocus></div>
    <div class="field"><label for="password">Senha</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
    <label class="inline-check"><input type="checkbox" name="remember" value="1"> Manter conectado neste aparelho</label>
    <button class="btn primary" type="submit">Entrar</button>
    <a class="btn" href="{{ route('password.request') }}">Esqueci minha senha</a>
  </form>
</div>
@endsection
