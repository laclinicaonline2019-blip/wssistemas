@extends('portal.guest', ['title' => 'Criar senha'])

@section('content')
    @if (! $record)
        <h1>Link inválido</h1>
        <p>Este link expirou ou já foi utilizado. Peça um novo à recepção da clínica.</p>
        <p><a class="btn" href="{{ route('portal.login') }}">Ir para o login</a></p>
    @else
        <h1>{{ $record->purpose === 'activation' ? 'Ative seu acesso' : 'Nova senha' }}</h1>
        <p class="muted">Crie uma senha com pelo menos {{ config('aivexa.security.password_min_length') }} caracteres, misturando letras, números e símbolos.</p>
        @include('partials.flash')
        <form method="post" action="{{ route('portal.activate.store', $token) }}" class="stack" novalidate>
            @csrf
            <div class="field"><label for="password">Senha</label>
                <input id="password" name="password" type="password" class="input @error('password') is-invalid @enderror" autocomplete="new-password" required>
                @error('password')<div class="field-error">{{ $message }}</div>@enderror</div>
            <div class="field"><label for="password_confirmation">Repita a senha</label>
                <input id="password_confirmation" name="password_confirmation" type="password" class="input" autocomplete="new-password" required></div>
            <label class="check small"><input type="checkbox" name="terms" value="1" required>
                <span>Entendo que o portal mostra meus dados de saúde, que não devo compartilhar minha senha e que meus acessos são registrados (LGPD).</span></label>
            @error('terms')<div class="field-error">{{ $message }}</div>@enderror
            <button type="submit" class="btn btn-brand btn-block">Salvar senha e entrar</button>
        </form>
    @endif
@endsection
