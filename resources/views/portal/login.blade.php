@extends('portal.guest', ['title' => 'Entrar'])

@section('content')
    <h1>Portal do paciente</h1>
    <p class="muted">Entre com seu CPF (ou e-mail) e senha.</p>
    @include('partials.flash', ['hideErrorSummary' => true])
    <form method="post" action="{{ route('portal.login.attempt') }}" class="stack" novalidate>
        @csrf
        <div class="field">
            <label for="login">CPF ou e-mail</label>
            <input id="login" name="login" class="input @error('login') is-invalid @enderror" value="{{ old('login') }}" autocomplete="username" required autofocus>
            @error('login')<div class="field-error" role="alert">{{ $message }}</div>@enderror
        </div>
        <div class="field">
            <label for="password">Senha</label>
            <input id="password" name="password" type="password" class="input" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn-brand btn-block mt-1">Entrar</button>
    </form>
    <p class="small mt-2"><a href="{{ route('portal.forgot') }}">Esqueci minha senha</a></p>
    <p class="small muted mt-2">Ainda não tem acesso? Peça o link de ativação na recepção da clínica.</p>
@endsection
