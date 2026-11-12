<!doctype html>
<html lang="pt-BR">
<head>@include('partials.head')</head>
<body>
@include('partials.stage')
<div class="wrap" @yield('wrapattrs')>
  <header class="top">
    <div class="brand"><div class="mark">WS</div><div><h1>WS Finanças em Dia</h1><small class="acct">{{ $account->name }} · {{ \App\Models\Membership::ROLES[$membership->role] ?? $membership->role }}</small></div></div>
    <div class="tools">@yield('tools')</div>
  </header>
  @php($cur = request()->route()?->getName())
  <nav class="menu" aria-label="Menu">
    <a href="{{ route('app') }}" @if($cur==='app') aria-current="page" @endif>Painel</a>
    @if (can_do('empresas.gerenciar'))<a href="{{ route('companies.index') }}" @if($cur==='companies.index') aria-current="page" @endif>Empresas e contas</a>@endif
    @if (can_do('usuarios.gerenciar'))<a href="{{ route('users.index') }}" @if($cur==='users.index') aria-current="page" @endif>Usuários</a>@endif
    @if (can_do('whatsapp.gerenciar'))<a href="{{ route('whatsapp.index') }}" @if($cur==='whatsapp.index') aria-current="page" @endif>WhatsApp</a>@endif
    @if (can_do('dados.importar'))<a href="{{ route('import.show') }}" @if($cur==='import.show') aria-current="page" @endif>Importar</a>@endif
    @if (can_do('assinatura.gerenciar'))<a href="{{ route('billing.show') }}" @if($cur==='billing.show') aria-current="page" @endif>Assinatura</a>@endif
    @if (can_do('lgpd.gerenciar'))<a href="{{ route('lgpd.show') }}" @if($cur==='lgpd.show') aria-current="page" @endif>Privacidade</a>@endif
    @if (can_do('auditoria.ver'))<a href="{{ route('audit.index') }}" @if($cur==='audit.index') aria-current="page" @endif>Auditoria</a>@endif
    <a href="{{ route('security.show') }}">Segurança</a>
    @if (auth()->user()->is_platform_admin)<a href="{{ route('admin.dashboard') }}">Proprietário</a>@endif
    @if (isset($memberships) && $memberships->count() > 1)
      <form method="post" action="{{ route('app.switch') }}" class="inline">@csrf
        <select name="membership_id" aria-label="Trocar de cliente" data-autosubmit>
          @foreach ($memberships as $m)<option value="{{ $m->id }}" @selected($m->id === $membership->id)>{{ $m->account->name }}</option>@endforeach
        </select></form>
    @endif
    <form method="post" action="{{ route('logout') }}" class="inline">@csrf<button type="submit">Sair</button></form>
  </nav>
  @if (! $account->canWrite())
    <div class="banner bad"><span>Assinatura {{ $account->status === 'canceled' ? 'cancelada' : 'suspensa' }}: seus dados estão preservados e disponíveis para consulta, mas novos lançamentos estão bloqueados.</span>
      @if (can_do('assinatura.gerenciar'))<a class="btn sm" href="{{ route('billing.show') }}">Regularizar</a>@endif</div>
  @elseif ($account->subscription?->status === 'past_due')
    <div class="banner warn"><span>Há uma cobrança da assinatura em aberto. Regularize para evitar o bloqueio de novos lançamentos.</span>
      @if (can_do('assinatura.gerenciar'))<a class="btn sm" href="{{ route('billing.show') }}">Ver cobrança</a>@endif</div>
  @elseif ($account->subscription?->status === 'trialing' && $account->subscription->trial_ends_at)
    <div class="banner info"><span>Teste grátis até {{ $account->subscription->trial_ends_at->format('d/m/Y') }}.</span>
      @if (can_do('assinatura.gerenciar'))<a class="btn sm" href="{{ route('billing.show') }}">Assinar</a>@endif</div>
  @endif
  @include('partials.flash')
  @yield('content')
  <p class="foot"><a href="{{ route('privacy') }}">Privacidade</a> · <a href="{{ route('terms') }}">Termos</a> · Suporte: {{ config('wsf.brand.support_email') }}</p>
</div>
<script src="{{ asset('assets/js/ui.js') }}?v={{ filemtime(public_path('assets/js/ui.js')) }}"></script>
@stack('scripts')
</body>
</html>
