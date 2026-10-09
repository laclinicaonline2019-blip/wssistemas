<!doctype html>
<html lang="pt-BR">
<head>@include('partials.head')</head>
<body>
<main class="auth">
    <section class="auth__art" aria-hidden="true">
        <img src="{{ asset('assets/img/logo-full.webp') }}" alt="{{ config('wsclinic.brand.name') }}" width="420" height="249">
        <p>Gestão clínica inteligente, segura e multiempresa — agenda, prontuário, financeiro e atendimento com IA em uma única plataforma.</p>
    </section>
    <section class="auth__panel">
        <div class="auth__box">
            @if (config('wsclinic.stage') !== 'production')
                <p><span class="stage-ribbon">Ambiente: {{ config('wsclinic.stage') }}</span></p>
            @endif
            @yield('content')
        </div>
    </section>
</main>
</body>
</html>
