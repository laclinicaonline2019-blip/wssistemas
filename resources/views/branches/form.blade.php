@extends('layouts.app', ['title' => $branch->exists ? 'Editar filial' : 'Nova filial'])

@section('content')
<div class="page-head">
    <div><h1>{{ $branch->exists ? 'Editar filial' : 'Nova filial' }}</h1>
        <p>{{ $branch->exists ? $branch->name : 'Cadastre uma nova unidade de atendimento.' }}</p></div>
    <a class="btn" href="{{ route('branches.index') }}">Voltar</a>
</div>

<form method="post" action="{{ $branch->exists ? route('branches.update', $branch) : route('branches.store') }}" class="card">
    @csrf
    @if ($branch->exists) @method('put') @endif
    <div class="card__body">
        <div class="form-grid">
            <x-field name="name" label="Nome" :value="$branch->name" col="col-8" required maxlength="150" />
            <x-field name="code" label="Código" :value="$branch->code" col="col-4" required maxlength="30" help="Ex.: CENTRO, FIL02" />
            <x-field name="document" label="CNPJ da filial" :value="$branch->document" col="col-4" mask="cnpj" />
            <x-field name="phone" label="Telefone" :value="$branch->phone" col="col-4" mask="phone" />
            <x-field name="email" label="E-mail" type="email" :value="$branch->email" col="col-4" />
            <x-field name="zip_code" label="CEP" :value="$branch->zip_code" col="col-3" mask="cep" />
            <x-field name="street" label="Logradouro" :value="$branch->street" col="col-6" />
            <x-field name="number" label="Número" :value="$branch->number" col="col-3" />
            <x-field name="complement" label="Complemento" :value="$branch->complement" col="col-4" />
            <x-field name="district" label="Bairro" :value="$branch->district" col="col-4" />
            <x-field name="city" label="Cidade" :value="$branch->city" col="col-3" />
            <x-field name="state" label="UF" :value="$branch->state" col="col-2" maxlength="2" />
            <div class="field col-6">
                <label for="f-timezone">Fuso horário</label>
                <select id="f-timezone" name="timezone" class="input">
                    @foreach (['America/Sao_Paulo', 'America/Bahia', 'America/Fortaleza', 'America/Recife', 'America/Belem', 'America/Manaus', 'America/Cuiaba', 'America/Campo_Grande', 'America/Porto_Velho', 'America/Boa_Vista', 'America/Rio_Branco', 'America/Noronha'] as $tz)
                        <option value="{{ $tz }}" @selected(old('timezone', $branch->timezone ?? 'America/Sao_Paulo') === $tz)>{{ $tz }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="form-actions">
            <a class="btn" href="{{ route('branches.index') }}">Cancelar</a>
            <button class="btn btn-primary" type="submit">Salvar</button>
        </div>
    </div>
</form>
@endsection
