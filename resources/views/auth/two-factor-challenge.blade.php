@extends('layouts.guest', ['title' => 'Verificação em duas etapas'])

@section('content')
    <h1>Verificação em duas etapas</h1>
    <p class="muted">Informe o código de 6 dígitos do seu aplicativo autenticador.</p>

    <form method="post" action="{{ route('two-factor.verify') }}" class="stack">
        @csrf
        <div class="field">
            <label for="code">Código</label>
            <input id="code" name="code" class="input @error('code') is-invalid @enderror" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]*" autofocus>
            @error('code')<div class="field-error" role="alert">{{ $message }}</div>@enderror
        </div>
        <details>
            <summary class="small">Usar um código de recuperação</summary>
            <div class="field mt-1">
                <label for="recovery_code">Código de recuperação</label>
                <input id="recovery_code" name="recovery_code" class="input" autocomplete="off">
            </div>
        </details>
        <button type="submit" class="btn btn-brand btn-block">Verificar</button>
    </form>
    <p class="small mt-2"><a href="{{ route('login') }}">Voltar ao login</a></p>
@endsection
