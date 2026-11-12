@extends('layouts.guest')
@section('title', 'Entrar · Pesquisa Farma')
@section('content')
<div class="card">
  <h2>Acesso da equipe e farmácias</h2>
  <form method="post" action="{{ url('/login') }}">@csrf
    <div class="f"><label>E-mail</label><input type="email" name="email" value="{{ old('email') }}" required autofocus></div>
    <div class="f"><label>Senha</label><input type="password" name="password" required></div>
    <div class="f"><label class="check"><input type="checkbox" name="remember" value="1"> Manter conectado</label></div>
    <button class="btn p lg" style="width:100%;justify-content:center">Entrar</button>
  </form>
</div>
<p class="muted small" style="text-align:center">É cliente? <a href="{{ route('portal.login') }}">Acesse a área do cliente</a></p>
@endsection
