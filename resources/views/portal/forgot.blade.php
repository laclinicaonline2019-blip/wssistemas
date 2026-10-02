@extends('portal.guest', ['title' => 'Esqueci a senha'])

@section('content')
    <h1>Esqueci minha senha</h1>
    <p class="muted">Informe seu CPF e data de nascimento. Se houver acesso ativo com e-mail cadastrado, enviaremos um link para criar uma nova senha.</p>
    @include('partials.flash')
    <form method="post" action="{{ route('portal.forgot.store') }}" class="stack" novalidate>
        @csrf
        <div class="field"><label for="cpf">CPF</label><input id="cpf" name="cpf" class="input" data-mask="cpf" inputmode="numeric" value="{{ old('cpf') }}" required></div>
        <div class="field"><label for="birth_date">Data de nascimento</label><input id="birth_date" name="birth_date" type="date" class="input" value="{{ old('birth_date') }}" required></div>
        <button type="submit" class="btn btn-brand btn-block">Enviar link</button>
    </form>
    <p class="small mt-2"><a href="{{ route('portal.login') }}">Voltar ao login</a></p>
@endsection
