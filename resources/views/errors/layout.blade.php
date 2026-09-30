<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('code') · {{ config('aivexa.brand.name') }}</title>
<link rel="stylesheet" href="{{ asset('assets/css/app.css') }}">
</head>
<body>
<main class="auth__panel error-page">
    <div class="auth__box">
        <img src="{{ asset('assets/img/icon-64.png') }}" alt="" width="48" height="48">
        <h1 class="mt-2">@yield('title')</h1>
        <p class="muted">@yield('message')</p>
        <a class="btn btn-primary" href="{{ url('/') }}">Ir para o início</a>
    </div>
</main>
</body>
</html>
