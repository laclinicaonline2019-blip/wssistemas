@php
    $links = [
        'portal.home' => 'Início', 'portal.appointments' => 'Consultas', 'portal.book' => 'Agendar',
        'portal.documents' => 'Documentos', 'portal.payments' => 'Pagamentos', 'portal.doctors' => 'Médicos', 'portal.profile' => 'Meus dados',
    ];
@endphp
<!doctype html>
<html lang="pt-BR">
<head>@include('partials.head', ['title' => ($title ?? 'Portal').' · '.$company->trade_name])</head>
<body class="portal">
<header class="portal-top">
    <div class="portal-top__inner">
        <div class="portal-brand"><img src="{{ asset('assets/img/icon-64.png') }}" alt="" width="30" height="30"><span><strong>{{ $company->trade_name }}</strong><span class="small muted">Portal do paciente</span></span></div>
        <form method="post" action="{{ route('portal.logout') }}">@csrf<button class="btn btn-sm" type="submit">Sair</button></form>
    </div>
    <nav class="portal-nav" aria-label="Portal">
        @foreach ($links as $route => $label)
            @if ($route !== 'portal.book' || (bool) $company->setting('portal.booking_enabled', true))
                <a href="{{ route($route) }}" class="{{ request()->routeIs($route) ? 'is-active' : '' }}" @if (request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>
            @endif
        @endforeach
    </nav>
</header>
<main class="portal-main">
    <p class="small muted">Olá, <strong>{{ $patient->displayName() }}</strong></p>
    @include('partials.flash')
    @yield('content')
    <p class="small muted mt-3">Dúvidas sobre seus dados ou resultados? Fale com a clínica{{ $company->phone ? ' pelo '.\App\Core\Support\Format::phone($company->phone) : '' }}. Acesso protegido e registrado (LGPD).</p>
</main>
</body>
</html>
