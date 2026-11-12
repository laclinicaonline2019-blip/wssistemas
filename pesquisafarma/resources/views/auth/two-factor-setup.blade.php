@extends('layouts.app')
@section('title', 'Minha segurança')
@section('content')
<div class="top"><div><h1>🔐 Minha segurança</h1><div class="sub">Verificação em duas etapas: além da senha, um código do celular a cada login.</div></div></div>
<div class="card" style="max-width:640px">
@if($user->two_factor_confirmed_at)
  <div class="alert ok">✔ Ativada desde {{ $user->two_factor_confirmed_at->format('d/m/Y H:i') }}.</div>
  <form method="post" action="{{ route('2fa.disable') }}" class="row">@csrf<input type="password" name="password" placeholder="Sua senha para desativar" required><button class="btn r auto">Desativar</button></form>
@else
  <ol class="small"><li>Instale um aplicativo autenticador (Google Authenticator, Microsoft Authenticator ou Authy).</li><li>Leia o QR Code abaixo (ou digite a chave).</li><li>Digite o código de 6 dígitos que aparece no aplicativo.</li></ol>
  <div style="background:#fff;display:inline-block;padding:8px;border:1px solid var(--line);border-radius:8px">{!! $qr !!}</div>
  <p class="small">Chave: <code>{{ trim(chunk_split($secret, 4, ' ')) }}</code></p>
  <form method="post" action="{{ route('2fa.enable') }}" class="row">@csrf<input name="code" inputmode="numeric" maxlength="6" placeholder="Código de 6 dígitos" required><button class="btn g auto">Ativar</button></form>
@endif
</div>
@endsection
