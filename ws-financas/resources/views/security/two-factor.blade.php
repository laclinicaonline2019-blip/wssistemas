@extends('layouts.guest')
@section('title', 'Ativar verificação em duas etapas')
@section('content')
<form class="card auth" method="post" action="{{ route('security.2fa.enable') }}">@csrf
  <h2>Ativar verificação em duas etapas</h2>
  <p class="muted mt0">1. Instale um aplicativo autenticador (Google Authenticator, Microsoft Authenticator, Authy).<br>2. Leia o QR Code abaixo ou digite a chave.<br>3. Informe o código de 6 dígitos que aparece no aplicativo.</p>
  <div class="qr">{!! $qr !!}</div>
  <p class="small">Chave: <code>{{ trim(chunk_split($secret, 4, ' ')) }}</code></p>
  <div class="field"><label for="code">Código de 6 dígitos</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="10" autofocus></div>
  <button class="btn primary" type="submit">Ativar</button>
</form>
<form method="post" action="{{ route('logout') }}" class="auth" style="margin-top:10px">@csrf<button class="btn sm" type="submit">Sair</button></form>
@endsection
