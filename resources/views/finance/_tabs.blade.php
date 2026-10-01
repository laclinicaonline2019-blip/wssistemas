@php $me = auth()->user(); @endphp
<nav class="chips mb-2" aria-label="Financeiro">
    @if ($me->hasPermission('financeiro.visualizar') || $me->hasPermission('relatorio.financeiro'))<a class="btn btn-sm {{ request()->routeIs('finance.overview') ? 'btn-primary' : '' }}" href="{{ route('finance.overview') }}">Visão geral</a>@endif
    @if ($me->hasPermission('financeiro.visualizar') || $me->hasPermission('caixa.operar'))<a class="btn btn-sm {{ request()->routeIs('receivables.*') ? 'btn-primary' : '' }}" href="{{ route('receivables.index') }}">Contas a receber</a>@endif
    @if ($me->hasPermission('financeiro.visualizar'))<a class="btn btn-sm {{ request()->routeIs('payables.*') ? 'btn-primary' : '' }}" href="{{ route('payables.index') }}">Contas a pagar</a>@endif
    @if ($me->hasPermission('caixa.operar'))<a class="btn btn-sm {{ request()->routeIs('cash.index') ? 'btn-primary' : '' }}" href="{{ route('cash.index') }}">Meu caixa</a>@endif
    @if ($me->hasPermission('caixa.conferir') || $me->hasPermission('financeiro.visualizar'))<a class="btn btn-sm {{ request()->routeIs('cash.sessions', 'cash.show') ? 'btn-primary' : '' }}" href="{{ route('cash.sessions') }}">Conferência de caixa</a>@endif
</nav>
