@php $o = fn ($k, $d = '') => e($old[$k] ?? $d); @endphp
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Instalação · {{ config('aivexa.brand.name') }}</title>
<link rel="stylesheet" href="{{ asset('assets/css/app.css') }}">
</head>
<body>
<main class="content install">
    <div class="row mb-2"><img src="{{ asset('assets/img/icon-64.png') }}" alt="" width="40" height="40"><h1 class="mb-0">Instalação do {{ config('aivexa.brand.name') }}</h1></div>

    @if ($errors->has('install'))<div class="alert alert-error">{{ $errors->first('install') }}</div>@endif

    @if ($step === 'token')
        <form method="post" action="{{ url('instalar') }}" class="card"><div class="card__body stack">
            <p class="text-2">Informe o <strong>INSTALL_TOKEN</strong> definido no arquivo <code>.env</code> do servidor.</p>
            <label class="label" for="token">Token de instalação</label>
            <input id="token" name="token" type="password" class="input @if ($errors->has('token')) is-invalid @endif" required autofocus autocomplete="off">
            @if ($errors->has('token'))<div class="field-error">{{ $errors->first('token') }}</div>@endif
            <div><button class="btn btn-brand" type="submit">Continuar</button></div>
        </div></form>

    @elseif ($step === 'checks')
        <section class="card">
            <div class="card__head"><h2>1. Verificação do servidor</h2>
                @if ($ready)<span class="badge badge-success">Pronto</span>@else<span class="badge badge-danger">Pendências</span>@endif</div>
            <div class="table-wrap"><table class="table"><tbody>
                @foreach ($requirements as $label => $c)
                    <tr><td>{{ $label }}</td><td class="small text-2">{{ $c['detail'] }}</td>
                        <td>@if ($c['ok'])<span class="badge badge-success">OK</span>@elseif ($c['required'])<span class="badge badge-danger">Falha</span>@else<span class="badge badge-warning">Aviso</span>@endif</td></tr>
                @endforeach
                <tr><td>Banco de dados</td><td class="small text-2">{{ $database['detail'] }}</td>
                    <td>@if ($database['ok'])<span class="badge badge-success">OK</span>@else<span class="badge badge-danger">Falha</span>@endif</td></tr>
                @unless ($keyOk)
                    <tr><td>APP_KEY</td><td class="small text-2">O .env não é gravável: gere a chave e cole em APP_KEY.</td><td><span class="badge badge-danger">Falha</span></td></tr>
                @endunless
            </tbody></table></div>
        </section>

        @if ($ready)
            <form method="post" action="{{ url('instalar') }}" class="card mt-2">
                <input type="hidden" name="token" value="{{ $token }}">
                <input type="hidden" name="step" value="install">
                <div class="card__head"><h2>2. Primeira clínica e administradores</h2></div>
                <div class="card__body form-grid">
                    @if ($errors->any())<div class="col-12 alert alert-error"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
                    <div class="field col-6"><label class="label">Razão social</label><input name="legal_name" class="input" value="{{ $o('legal_name') }}" required></div>
                    <div class="field col-3"><label class="label">Nome fantasia</label><input name="trade_name" class="input" value="{{ $o('trade_name') }}" required></div>
                    <div class="field col-3"><label class="label">CNPJ</label><input name="document" class="input" value="{{ $o('document') }}" required></div>

                    <h3 class="col-12 mt-1">Administrador da clínica</h3>
                    <div class="field col-6"><label class="label">Nome</label><input name="admin_name" class="input" value="{{ $o('admin_name') }}" required></div>
                    <div class="field col-6"><label class="label">E-mail</label><input name="admin_email" type="email" class="input" value="{{ $o('admin_email') }}" required></div>
                    <div class="field col-6"><label class="label">Senha</label><input name="admin_password" type="password" class="input" autocomplete="new-password" required></div>
                    <div class="field col-6"><label class="label">Confirmar senha</label><input name="admin_password_confirmation" type="password" class="input" autocomplete="new-password" required></div>

                    <h3 class="col-12 mt-1">Super Admin da plataforma</h3>
                    <div class="field col-6"><label class="label">Nome</label><input name="super_name" class="input" value="{{ $o('super_name') }}" required></div>
                    <div class="field col-6"><label class="label">E-mail</label><input name="super_email" type="email" class="input" value="{{ $o('super_email') }}" required></div>
                    <div class="field col-6"><label class="label">Senha</label><input name="super_password" type="password" class="input" autocomplete="new-password" required></div>
                    <div class="field col-6"><label class="label">Confirmar senha</label><input name="super_password_confirmation" type="password" class="input" autocomplete="new-password" required></div>
                    <p class="col-12 help">Senhas: mínimo {{ config('aivexa.security.password_min_length') }} caracteres com maiúsculas, minúsculas, números e símbolos.</p>
                    <div class="col-12 form-actions"><button class="btn btn-brand" type="submit">Instalar</button></div>
                </div>
            </form>
        @endif

    @else
        <section class="card"><div class="card__body stack">
            <div class="alert alert-success">Instalação concluída. O instalador foi desativado.</div>
            <ul>@foreach ($checks as $name => $c)<li><strong>{{ $name }}</strong>: {{ $c['ok'] ? 'OK' : 'FALHA' }} — {{ $c['detail'] }}</li>@endforeach</ul>
            <p class="text-2">Por segurança, remova o valor de <code>INSTALL_TOKEN</code> do <code>.env</code> e configure o cron do cPanel (veja docs/HOSTGATOR.md).</p>
            <div><a class="btn btn-primary" href="{{ url('/login') }}">Ir para o login</a></div>
        </div></section>
    @endif
</main>
</body>
</html>
