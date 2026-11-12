@extends('layouts.guest')
@section('title', 'Instalação concluída')
@section('content')
<div class="card">
  <h2>Instalação concluída ✓</h2>
  <p class="mt0">Anote estas informações — algumas não serão mostradas de novo (também estão no arquivo .env, fora da pasta pública):</p>
  <ol>
    <li>Cron do cPanel (a cada minuto): <code>{{ $cron }}</code></li>
    <li>Webhook do WhatsApp (Meta): <code>{{ $webhook_whatsapp }}</code> — token de verificação: <code>{{ $verify_token }}</code></li>
    <li>Webhook do Asaas: <code>{{ $webhook_asaas }}</code> — token: <code>{{ $asaas_token }}</code></li>
    <li>Complete no .env: chaves do WhatsApp, do Asaas, do e-mail (SMTP) e, se quiser, da IA. Veja docs/HOSTGATOR.md.</li>
  </ol>
  <a class="btn primary" href="{{ route('login') }}">Entrar como {{ $admin_email }}</a>
</div>
@endsection
