<!doctype html>
<html lang="pt-BR">
<head>@include('partials.head')</head>
<body>
<main class="auth">
    <section class="auth__art" aria-hidden="true">
        <img src="{{ asset('assets/img/logo-full.webp') }}" alt="" width="420" height="420">
        <p>Gestão clínica inteligente, segura e multiempresa — agenda, prontuário, financeiro e atendimento com IA em uma única plataforma.</p>
    </section>
    <section class="auth__panel">
        <div class="auth__box">
            @if (config('aivexa.stage') !== 'production')
                <p><span class="stage-ribbon">Ambiente: {{ config('aivexa.stage') }}</span></p>
            @endif
            @yield('content')
        </div>
    </section>
</main>
</body>
</html>
