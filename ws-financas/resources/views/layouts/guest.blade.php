<!doctype html>
<html lang="pt-BR">
<head>@include('partials.head')</head>
<body>
@include('partials.stage')
<div class="wrap">
  <header class="top">
    <a class="brand" href="/" style="text-decoration:none;color:inherit"><div class="mark">WS</div><div><h1>WS Finanças em Dia</h1><small>Seu dinheiro em dia.</small></div></a>
    <div class="tools">@yield('tools')</div>
  </header>
  @include('partials.flash')
  @yield('content')
  <p class="foot">&copy; {{ date('Y') }} {{ config('wsf.brand.company_legal') }} · <a href="{{ route('privacy') }}">Privacidade</a> · <a href="{{ route('terms') }}">Termos de uso</a></p>
</div>
</body>
</html>
