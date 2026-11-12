@extends('layouts.guest')
@section('title', 'Verificação em duas etapas')
@section('content')
<form class="card auth" method="post" action="{{ route('login.2fa') }}">@csrf
  <h2>Verificação em duas etapas</h2>
  <p class="muted mt0">Digite o código de 6 dígitos do aplicativo autenticador (Google Authenticator, Microsoft Authenticator, Authy…) ou um código de recuperação.</p>
  <div class="field"><label for="code">Código</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus maxlength="20"></div>
  <button class="btn primary" type="submit">Confirmar</button>
</form>
@endsection
