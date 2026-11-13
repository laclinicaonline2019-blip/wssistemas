<!doctype html>
<html lang="pt-BR">
<head>@include('partials.head')</head>
<body>
@include('partials.stage')
<div class="wrap" style="max-width:1100px">
  <header class="top">
    <div class="brand"><div class="mark">WS</div><div><h1>WS Finanças — Proprietário</h1><small class="acct">Painel comercial da plataforma</small></div></div>
  </header>
  @php($cur = request()->route()?->getName())
  <nav class="menu" aria-label="Menu do proprietário">
    @foreach (['admin.dashboard' => 'Visão geral', 'admin.accounts.index' => 'Clientes', 'admin.plans.index' => 'Planos e preços', 'admin.invoices.index' => 'Faturamento', 'admin.usage' => 'Uso WhatsApp/IA', 'admin.system' => 'Sistema e backups', 'admin.updates' => 'Atualizações'] as $r => $l)
      <a href="{{ route($r) }}" @if($cur===$r) aria-current="page" @endif>{{ $l }}</a>
    @endforeach
    <a href="{{ route('security.show') }}">Segurança</a>
    <form method="post" action="{{ route('logout') }}" class="inline">@csrf<button type="submit">Sair</button></form>
  </nav>
  @include('partials.flash')
  @yield('content')
</div>
<script src="{{ asset('assets/js/ui.js') }}"></script>
@stack('scripts')
</body>
</html>
