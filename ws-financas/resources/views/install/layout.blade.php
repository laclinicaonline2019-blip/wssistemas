<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>Instalação — WS Finanças em Dia</title>
<link rel="icon" href="/favicon.png" type="image/png">
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="wrap" style="max-width:760px">
  <header class="top">
    <div class="brand"><div class="mark">WS</div><div><h1>WS Finanças em Dia</h1><small class="acct">Instalador web · versão {{ $version ?? config('app.version') }}</small></div></div>
  </header>
  @if (!empty($flash['ok']))<div class="banner good" role="status">{{ $flash['ok'] }}</div>@endif
  @if (!empty($flash['error']))<div class="banner bad" role="alert">{{ $flash['error'] }}</div>@endif
  @yield('content')
  <p class="foot">O instalador funciona só por HTTPS, exige o código gravado no servidor e é bloqueado definitivamente ao concluir.</p>
</div>
</body>
</html>
