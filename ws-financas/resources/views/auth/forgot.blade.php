@extends('layouts.guest')
@section('title', 'Recuperar acesso')
@section('content')
<form class="card auth" method="post" action="{{ route('password.email') }}">@csrf
  <h2>Recuperar acesso</h2>
  <p class="muted mt0">Informe o e-mail cadastrado na sua conta. Enviaremos para esse e-mail um link para você criar uma nova senha (válido por 60 minutos).</p>
  <div class="field"><label for="email">E-mail</label><input id="email" name="email" type="email" required autofocus></div>
  <button class="btn primary" type="submit">Enviar link</button>
  <a class="small" href="{{ route('login') }}">Voltar para entrar</a>
</form>
@endsection
