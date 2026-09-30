<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Painel — {{ $branch->name }}</title>
<link rel="stylesheet" href="{{ asset('assets/css/panel.css') }}?v={{ filemtime(public_path('assets/css/panel.css')) }}">
<script src="{{ asset('assets/js/panel.js') }}?v={{ filemtime(public_path('assets/js/panel.js')) }}" defer></script>
</head>
<body data-state-url="{{ route('panel.state', $token) }}">
<main class="panel">
    <header class="panel__top">
        <img src="{{ asset('assets/img/wordmark.webp') }}" alt="" height="28">
        <span class="panel__branch">{{ $branch->name }}</span>
        <span class="panel__clock" id="clock">--:--</span>
    </header>
    <section class="panel__current" id="current" aria-live="assertive">
        <div class="panel__label">SENHA</div>
        <div class="panel__code" id="cur-code">—</div>
        <div class="panel__name" id="cur-name"></div>
        <div class="panel__room" id="cur-room">Aguardando chamadas</div>
        <div class="panel__doctor" id="cur-doctor"></div>
    </section>
    <aside class="panel__history">
        <h2>Últimas chamadas</h2>
        <ol id="history"></ol>
    </aside>
    <button class="panel__sound" id="enable-sound" type="button">Toque aqui para ativar o som</button>
    <div class="panel__offline hidden" id="offline">Sem conexão — tentando novamente…</div>
</main>
</body>
</html>
