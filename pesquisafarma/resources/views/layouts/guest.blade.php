<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', 'Pesquisa Farma')</title>
<link rel="icon" href="{{ asset('img/icone.png') }}">
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v=4">
</head>
<body>
<div class="guest"><div class="box @yield('box')">
  <img class="logo" src="{{ asset('img/logo-640.webp') }}" alt="Pesquisa Farma — pesquise preços em várias farmácias e receba a melhor cotação">
  @include('partials.flash')
  @yield('content')
</div></div>
<script src="{{ asset('js/app.js') }}?v=1"></script>
</body>
</html>
