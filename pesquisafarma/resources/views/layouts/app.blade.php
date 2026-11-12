<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', 'Painel') · Pesquisa Farma</title>
<link rel="icon" href="{{ asset('img/icone.png') }}">
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v=1">
</head>
<body>
@php($u = auth()->user())
<div class="app">
  <aside class="side">
    <div class="brand"><img src="{{ asset('img/icone.png') }}" alt=""><b>Pesquisa<span>Farma</span></b></div>
    <nav>
    @if($u->isStaff())
      @php($inboxUnread = \App\Models\WaMessage::where('direction','in')->whereNull('read_at')->distinct('customer_id')->count('customer_id'))
      @php($nfPend = \App\Models\Receipt::where('status','pendente')->count())
      <div class="grp">Atendimento</div>
      <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'on' : '' }}">🏠 Painel</a>
      <a href="{{ route('inbox') }}" class="{{ request()->routeIs('inbox*') ? 'on' : '' }}">💬 Conversas @if($inboxUnread)<span class="pill-dot">{{ $inboxUnread }}</span>@endif</a>
      <a href="{{ route('quotes.index') }}" class="{{ request()->routeIs('quotes.*') ? 'on' : '' }}">💊 Cotações</a>
      <a href="{{ route('orders.index') }}" class="{{ request()->routeIs('orders.*') ? 'on' : '' }}">🧾 Pedidos</a>
      <a href="{{ route('receipts.index') }}" class="{{ request()->routeIs('receipts.*') ? 'on' : '' }}">📄 Notas fiscais @if($nfPend)<span class="pill-dot">{{ $nfPend }}</span>@endif</a>
      <a href="{{ route('customers.index') }}" class="{{ request()->routeIs('customers.*') ? 'on' : '' }}">👥 Clientes</a>
      <div class="grp">Marketplace</div>
      <a href="{{ route('promotions.index') }}" class="{{ request()->routeIs('promotions.*') ? 'on' : '' }}">🔥 Promoções</a>
      <a href="{{ route('loyalty') }}" class="{{ request()->routeIs('loyalty*') ? 'on' : '' }}">🎁 Pontos e brindes</a>
      <a href="{{ route('commissions.index') }}" class="{{ request()->routeIs('commissions.*') ? 'on' : '' }}">💰 Comissões</a>
      <a href="{{ route('reports') }}" class="{{ request()->routeIs('reports') ? 'on' : '' }}">📊 Relatórios</a>
      <a href="{{ route('activity') }}" class="{{ request()->routeIs('activity') ? 'on' : '' }}">🕘 Atividades</a>
      @if($u->isAdmin())
      <div class="grp">Cadastros</div>
      <a href="{{ route('pharmacies.index') }}" class="{{ request()->routeIs('pharmacies.*') ? 'on' : '' }}">🏪 Farmácias</a>
      <a href="{{ route('neighborhoods.index') }}" class="{{ request()->routeIs('neighborhoods.*') ? 'on' : '' }}">📍 Bairros</a>
      <a href="{{ route('users.index') }}" class="{{ request()->routeIs('users.*') ? 'on' : '' }}">🔑 Usuários</a>
      <a href="{{ route('settings') }}" class="{{ request()->routeIs('settings') ? 'on' : '' }}">⚙️ Configurações</a>
      @endif
    @else
      <div class="grp">{{ $u->pharmacy?->trade_name }}</div>
      <a href="{{ route('panel.home') }}" class="{{ request()->routeIs('panel.home','panel.quote') ? 'on' : '' }}">💊 Cotações</a>
      <a href="{{ route('panel.orders') }}" class="{{ request()->routeIs('panel.orders') ? 'on' : '' }}">🧾 Pedidos</a>
      <a href="{{ route('panel.sales') }}" class="{{ request()->routeIs('panel.sales') ? 'on' : '' }}">💰 Vendas e comissão</a>
      <a href="{{ route('panel.promotions') }}" class="{{ request()->routeIs('panel.promotions') ? 'on' : '' }}">🔥 Promoções do dia</a>
    @endif
    </nav>
    <div class="who">{{ $u->name }}<br><span class="muted">{{ \App\Models\User::ROLES[$u->role] ?? $u->role }}</span>
      <form method="post" action="{{ route('logout') }}" style="margin-top:6px">@csrf<button class="btn sm">Sair</button></form>
    </div>
  </aside>
  <main class="main">
    @include('partials.flash')
    @yield('content')
  </main>
</div>
<script src="{{ asset('js/app.js') }}?v=1"></script>
</body>
</html>
