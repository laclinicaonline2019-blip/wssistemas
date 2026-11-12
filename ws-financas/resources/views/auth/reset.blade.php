@extends('layouts.guest')
@section('title', 'Nova senha')
@section('content')
<form class="card auth" method="post" action="{{ route('password.update') }}">@csrf
  <input type="hidden" name="token" value="{{ $token }}">
  <h2>Criar nova senha</h2>
  <div class="field"><label for="email">E-mail</label><input id="email" name="email" type="email" required value="{{ $email }}"></div>
  <div class="field"><label for="password">Nova senha</label><input id="password" name="password" type="password" autocomplete="new-password" required></div>
  <div class="field"><label for="password_confirmation">Repita a senha</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required></div>
  <p class="small muted mt0">Mínimo de 10 caracteres, com letras maiúsculas e minúsculas, números e símbolos.</p>
  <button class="btn primary" type="submit">Salvar senha</button>
</form>
@endsection
