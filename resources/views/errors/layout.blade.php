<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('code') · {{ config('wsclinic.brand.name') }}</title>
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="stylesheet" href="{{ asset('assets/css/app.css') }}">
</head>
<body>
<main class="auth__panel error-page">
    <div class="auth__box">
        <img src="{{ asset('assets/img/logo-full.webp') }}" alt="{{ config('wsclinic.brand.name') }}" width="180" height="107" class="error-logo">
        <h1 class="mt-2">@yield('title')</h1>
        <p class="muted">@yield('message')</p>
        <a class="btn btn-primary" href="{{ url('/') }}">Ir para o início</a>
    </div>
</main>
</body>
</html>
