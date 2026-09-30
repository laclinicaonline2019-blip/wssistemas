@extends('layouts.guest', ['title' => 'Entrar'])

@section('content')
    <h1>Bem-vindo de volta</h1>
    <p class="muted">Acesse sua conta para continuar.</p>

    @include('partials.flash', ['hideErrorSummary' => true])

    <form method="post" action="{{ route('login.attempt') }}" class="stack" novalidate>
        @csrf
        <div class="field">
            <label for="email">E-mail</label>
            <input id="email" name="email" type="email" class="input @error('email') is-invalid @enderror" value="{{ old('email') }}" autocomplete="username" required autofocus>
            @error('email')<div class="field-error" role="alert">{{ $message }}</div>@enderror
        </div>
        <div class="field">
            <label for="password">Senha</label>
            <input id="password" name="password" type="password" class="input" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn-brand btn-block mt-1">Entrar</button>
    </form>

    <p class="small muted mt-3">Acesso protegido e auditado. Tentativas inválidas repetidas bloqueiam a conta temporariamente.</p>
@endsection
