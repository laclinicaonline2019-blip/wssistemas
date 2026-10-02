@extends('portal.layout', ['title' => 'Meus dados'])

@php use App\Core\Support\Format; @endphp

@section('content')
<div class="page-head"><div><h1>Meus dados</h1><p>Para corrigir algum dado, fale com a recepção (as alterações ficam registradas).</p></div></div>
<div class="grid grid-2">
    <section class="card"><div class="card__body">
        <dl class="dl">
            <dt>Nome</dt><dd>{{ $patient->displayName() }}</dd>
            <dt>Prontuário</dt><dd>#{{ $patient->record_number }}</dd>
            <dt>CPF</dt><dd>{{ Format::cpfMasked($patient->cpf) ?? '—' }}</dd>
            <dt>Nascimento</dt><dd>{{ $patient->birth_date?->format('d/m/Y') ?? '—' }}</dd>
            <dt>Celular</dt><dd>{{ Format::phone($patient->whatsapp ?? $patient->phone) ?? '—' }}</dd>
            <dt>E-mail de acesso</dt><dd>{{ $account->email ?? '—' }}</dd>
            <dt>Convênios</dt><dd>@forelse ($patient->insurances as $i){{ $i->insurer_name }} · {{ $i->card_number }}{{ $i->valid_until ? ' (validade '.$i->valid_until->format('d/m/Y').')' : '' }}<br>@empty Particular @endforelse</dd>
        </dl>
    </div></section>
    <section class="card">
        <div class="card__head"><h2>Trocar senha</h2></div>
        <form method="post" action="{{ route('portal.password') }}" class="card__body stack">
            @csrf @method('put')
            <div class="field"><label for="cp">Senha atual</label><input id="cp" name="current_password" type="password" class="input" autocomplete="current-password" required>@error('current_password')<div class="field-error">{{ $message }}</div>@enderror</div>
            <div class="field"><label for="np">Nova senha</label><input id="np" name="password" type="password" class="input" autocomplete="new-password" required>@error('password')<div class="field-error">{{ $message }}</div>@enderror</div>
            <div class="field"><label for="np2">Repita a nova senha</label><input id="np2" name="password_confirmation" type="password" class="input" autocomplete="new-password" required></div>
            <button class="btn btn-primary" type="submit">Salvar nova senha</button>
        </form>
    </section>
</div>
@endsection
