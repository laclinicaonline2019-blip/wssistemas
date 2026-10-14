{{-- Cabeçalho do Melhor Sprint (logo + navegação do módulo). $subtitle opcional. --}}
@push('head')
<link rel="stylesheet" href="{{ asset('assets/css/sprint.css') }}?v={{ filemtime(public_path('assets/css/sprint.css')) }}">
<script src="{{ asset('assets/js/sprint.js') }}?v={{ filemtime(public_path('assets/js/sprint.js')) }}" defer></script>
@endpush
@php $me = auth()->user(); @endphp
<div class="ms-hero">
    <div class="ms-hero__brand">
        <img src="{{ asset('assets/img/melhor-sprint-mark.webp') }}" alt="" width="150" height="52">
        <div><h1>Melhor Sprint</h1><p>{{ $subtitle ?? 'Sistema de pagamento com divisão entre profissionais e clínica' }}</p></div>
    </div>
    <nav class="ms-tabs" aria-label="Melhor Sprint">
        @if ($me->hasPermission('sprint.vender'))<a class="btn btn-sm btn-sprint" href="{{ route('sprint.sales.create') }}">+ Nova venda</a>@endif
        @if ($me->hasPermission('sprint.vender') || $me->hasPermission('sprint.repasse'))<a class="btn btn-sm {{ request()->routeIs('sprint.index', 'sprint.sales.show') ? 'is-active' : '' }}" href="{{ route('sprint.index') }}">Vendas</a>@endif
        @if ($me->hasPermission('sprint.repasse'))<a class="btn btn-sm {{ request()->routeIs('sprint.reports.*') ? 'is-active' : '' }}" href="{{ route('sprint.reports.index') }}">Repasses e relatórios</a>@endif
        @if ($me->hasPermission('sprint.profissionais'))<a class="btn btn-sm {{ request()->routeIs('sprint.professionals.*') ? 'is-active' : '' }}" href="{{ route('sprint.professionals.index') }}">Profissionais</a>@endif
        @if ($me->hasPermission('sprint.configurar'))<a class="btn btn-sm {{ request()->routeIs('sprint.settings') ? 'is-active' : '' }}" href="{{ route('sprint.settings') }}">Configurações</a>@endif
    </nav>
</div>
