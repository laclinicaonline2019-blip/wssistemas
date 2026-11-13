@extends('install.layout')
@section('content')
<div class="card">
  <h2>Instalação concluída ✓</h2>
  <p class="mt0">O instalador foi bloqueado. Faça agora, nesta ordem:</p>
  <ol>
    <li><b>Cron</b> — cPanel → Trabalhos Cron → “Uma vez por minuto (* * * * *)” → comando:<br><code style="overflow-wrap:anywhere">{{ $cron }}</code><br><span class="small muted">Se o cron acusar erro, troque o início pelo PHP 8.3 do seu servidor: <code>/usr/local/bin/ea-php83</code> ou <code>/opt/cpanel/ea-php83/root/usr/bin/php</code>.</span></li>
    <li><b>Cópia de segurança do .env</b> — Gerenciador de Arquivos → baixe <code style="overflow-wrap:anywhere">{{ $env_path }}</code> e guarde fora do servidor (contém APP_KEY e BACKUP_PASSWORD).</li>
    <li><b>Entrar</b> em <a href="{{ $url }}/entrar">{{ $url }}/entrar</a> com o administrador principal e ativar a verificação em duas etapas.</li>
    <li><b>E-mail, WhatsApp e Asaas</b> — preencha no .env (Gerenciador de Arquivos → Editar). URLs dos webhooks: <code>{{ $webhook_whatsapp }}</code> e <code>{{ $webhook_asaas }}</code>; os tokens já foram gerados no .env.</li>
    <li><b>Proprietário → Sistema</b> mostra o que ainda falta configurar.</li>
  </ol>
  <a class="btn primary" href="{{ $url }}/entrar">Ir para o login</a>
</div>
@endsection
