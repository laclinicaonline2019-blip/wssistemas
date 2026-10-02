@php $me = auth()->user(); @endphp
<nav class="chips mb-2" aria-label="Convênios">
    @if ($me->hasPermission('convenio.visualizar') || $me->hasPermission('convenio.gerenciar'))<a class="btn btn-sm {{ request()->routeIs('insurers.*') ? 'btn-primary' : '' }}" href="{{ route('insurers.index') }}">Convênios e tabelas</a>@endif
    @if ($me->hasPermission('convenio.gerenciar'))<a class="btn btn-sm {{ request()->routeIs('procedures.*') ? 'btn-primary' : '' }}" href="{{ route('procedures.index') }}">Procedimentos (TUSS)</a>@endif
    @if ($me->hasPermission('convenio.autorizar') || $me->hasPermission('convenio.faturar'))<a class="btn btn-sm {{ request()->routeIs('authorizations.*') ? 'btn-primary' : '' }}" href="{{ route('authorizations.index') }}">Autorizações</a>@endif
    @if ($me->hasPermission('convenio.faturar'))<a class="btn btn-sm {{ request()->routeIs('guides.*') ? 'btn-primary' : '' }}" href="{{ route('guides.index') }}">Guias</a>
        <a class="btn btn-sm {{ request()->routeIs('batches.*') ? 'btn-primary' : '' }}" href="{{ route('batches.index') }}">Lotes e glosas</a>@endif
</nav>
