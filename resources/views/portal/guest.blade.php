<!doctype html>
<html lang="pt-BR">
<head>@include('partials.head', ['title' => ($title ?? 'Portal do paciente').' · '.$company->trade_name])</head>
<body class="portal">
<main class="auth">
    <section class="auth__art" aria-hidden="true">
        <img src="{{ asset('assets/img/logo-full.webp') }}" alt="" width="420" height="420">
        <p>Portal do paciente — {{ $company->trade_name }}. Suas consultas, documentos e pagamentos em um só lugar, com acesso protegido.</p>
    </section>
    <section class="auth__panel">
        <div class="auth__box">
            <p class="small muted">{{ $company->trade_name }} · Portal do paciente</p>
            @yield('content')
        </div>
    </section>
</main>
</body>
</html>
