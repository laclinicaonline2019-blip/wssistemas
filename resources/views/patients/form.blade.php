@extends('layouts.app', ['title' => $patient->exists ? 'Editar paciente' : 'Novo paciente'])

@php
    use App\Core\Support\Format;
    $contacts = old('contacts', $patient->exists ? $patient->contacts->map->only(['type', 'name', 'relationship', 'cpf', 'phone', 'email'])->all() : []);
    $insurances = old('insurances', $patient->exists ? $patient->insurances->map(fn ($i) => $i->only(['id', 'insurer_id', 'plan_id', 'insurer_name', 'plan_name', 'card_number', 'is_primary']) + ['valid_until' => $i->valid_until?->format('Y-m-d')])->all() : []);
    $insurerOptions = $insurers ?? collect();
    $v = fn ($k, $fmt = null) => old($k, $fmt ? $fmt($patient->{$k}) : $patient->{$k});
@endphp

@section('content')
<div class="page-head">
    <div><h1>{{ $patient->exists ? $patient->displayName() : 'Novo paciente' }}</h1>
        <p>@if ($patient->exists) Prontuário <span class="record-no">#{{ $patient->record_number }}</span> @else O nº de prontuário é gerado automaticamente. @endif</p></div>
    <a class="btn" href="{{ $patient->exists ? route('patients.show', $patient) : route('patients.index') }}">Voltar</a>
</div>

@if (session('duplicates'))
    <div class="alert alert-warning">
        <strong>Encontramos pacientes parecidos.</strong> Confira se não é um deles antes de criar um novo cadastro:
        <ul class="mb-0">@foreach (session('duplicates') as $d)
            <li><a href="{{ route('patients.show', $d['id']) }}" target="_blank" rel="noopener">#{{ $d['record_number'] }} — {{ $d['name'] }}</a>
                {{ $d['birth_date'] ? '· '.\Illuminate\Support\Carbon::parse($d['birth_date'])->format('d/m/Y') : '' }} {{ $d['cpf_masked'] ? '· CPF '.$d['cpf_masked'] : '' }}</li>
        @endforeach</ul>
    </div>
@endif

<template id="contact-tpl">@include('patients._contact-row', ['i' => '__INDEX__', 'c' => []])</template>
<template id="insurance-tpl">@include('patients._insurance-row', ['i' => '__INDEX__', 'n' => [], 'insurerOptions' => $insurerOptions])</template>

<form method="post" action="{{ $patient->exists ? route('patients.update', $patient) : route('patients.store') }}" novalidate>
    @csrf
    @if ($patient->exists) @method('put') @endif

    <section class="card">
        <div class="card__head"><h2>Identificação</h2></div>
        <div class="card__body form-grid">
            <x-field name="name" label="Nome completo (registro civil)" :value="$v('name')" col="col-6" required maxlength="150" />
            <x-field name="social_name" label="Nome social" :value="$v('social_name')" col="col-6" help="Será usado no atendimento, chamadas e documentos (Decreto 8.727/2016)." />
            <x-field name="birth_date" label="Data de nascimento" type="date" :value="$v('birth_date', fn ($d) => $d?->format('Y-m-d'))" col="col-3" required />
            <div class="field col-3"><label for="f-sex">Sexo</label>
                <select id="f-sex" name="sex" class="input @error('sex') is-invalid @enderror"><option value="">—</option>
                    @foreach (\App\Modules\Patients\Models\Patient::SEXES as $k => $l)<option value="{{ $k }}" @selected($v('sex') === $k)>{{ $l }}</option>@endforeach
                </select></div>
            <x-field name="gender_identity" label="Identidade de gênero" :value="$v('gender_identity')" col="col-3" />
            <x-field name="cpf" label="CPF" :value="$v('cpf', fn ($c) => Format::cpf($c))" col="col-3" mask="cpf" help="Opcional para recém-nascidos e estrangeiros." />
            <x-field name="rg" label="RG" :value="$v('rg')" col="col-3" />
            <x-field name="rg_issuer" label="Órgão emissor" :value="$v('rg_issuer')" col="col-2" />
            <x-field name="cns" label="Cartão SUS (CNS)" :value="$v('cns')" col="col-3" maxlength="15" />
            <x-field name="mother_name" label="Nome da mãe" :value="$v('mother_name')" col="col-4" />
            <div class="field col-4"><label for="f-home">Unidade de cadastro</label>
                <select id="f-home" name="home_branch_id" class="input">
                    @foreach ($branches as $b)<option value="{{ $b->id }}" @selected(old('home_branch_id', $patient->home_branch_id ?? app(\App\Core\Tenancy\TenantContext::class)->branchId()) === $b->id)>{{ $b->name }}</option>@endforeach
                </select></div>
        </div>
    </section>

    <section class="card">
        <div class="card__head"><h2>Contato e endereço</h2></div>
        <div class="card__body form-grid">
            <x-field name="whatsapp" label="WhatsApp" :value="$v('whatsapp', fn ($p) => Format::phone($p))" col="col-3" mask="phone" />
            <x-field name="phone" label="Telefone" :value="$v('phone', fn ($p) => Format::phone($p))" col="col-3" mask="phone" />
            <x-field name="email" label="E-mail" type="email" :value="$v('email')" col="col-4" />
            <div class="field col-2"><label for="f-pref">Contato preferido</label>
                <select id="f-pref" name="preferred_contact" class="input"><option value="">—</option>
                    @foreach (['whatsapp' => 'WhatsApp', 'phone' => 'Telefone', 'email' => 'E-mail'] as $k => $l)<option value="{{ $k }}" @selected($v('preferred_contact') === $k)>{{ $l }}</option>@endforeach
                </select></div>
            <x-field name="zip_code" label="CEP" :value="$v('zip_code', fn ($c) => Format::cep($c))" col="col-2" mask="cep" data-cep-lookup help="Preenche o endereço." />
            <x-field name="street" label="Logradouro" :value="$v('street')" col="col-5" />
            <x-field name="number" label="Número" :value="$v('number')" col="col-2" />
            <x-field name="complement" label="Complemento" :value="$v('complement')" col="col-3" />
            <x-field name="district" label="Bairro" :value="$v('district')" col="col-4" />
            <x-field name="city" label="Cidade" :value="$v('city')" col="col-4" />
            <div class="field col-2"><label for="f-state">UF</label>
                <select id="f-state" name="state" class="input"><option value="">—</option>
                    @foreach (\App\Core\Validation\BrazilianStates::ALL as $uf)<option @selected($v('state') === $uf)>{{ $uf }}</option>@endforeach
                </select></div>
        </div>
    </section>

    <section class="card">
        <div class="card__head"><h2>Responsáveis e contatos</h2>
            <button type="button" class="btn btn-sm" data-row-add="#contact-rows" data-row-template="#contact-tpl"><svg><use href="#i-plus"/></svg>Adicionar</button></div>
        <div class="card__body">
            <p class="help">Obrigatório informar o <strong>responsável legal</strong> para menores de 18 anos.</p>
            @error('contacts')<div class="field-error">{{ $message }}</div>@enderror
            <div id="contact-rows" class="stack">
                @foreach ($contacts as $i => $c)@include('patients._contact-row', ['i' => $i, 'c' => $c])@endforeach
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card__head"><h2>Convênios</h2>
            <button type="button" class="btn btn-sm" data-row-add="#insurance-rows" data-row-template="#insurance-tpl"><svg><use href="#i-plus"/></svg>Adicionar</button></div>
        <div class="card__body">
            <div id="insurance-rows" class="stack">
                @foreach ($insurances as $i => $n)@include('patients._insurance-row', ['i' => $i, 'n' => $n, 'insurerOptions' => $insurerOptions])@endforeach
            </div>
            @if (! count($insurances))<p class="help">Sem convênio: atendimento particular.</p>@endif
        </div>
    </section>

    <section class="card">
        <div class="card__head"><h2>Observações administrativas</h2></div>
        <div class="card__body">
            <label class="sr-only" for="f-notes">Observações</label>
            <textarea id="f-notes" name="notes" class="input" rows="3" maxlength="2000" placeholder="Informações administrativas (não clínicas).">{{ $v('notes') }}</textarea>
            <p class="help">Informações clínicas pertencem ao prontuário, não ao cadastro.</p>
        </div>
    </section>

    <div class="form-actions">
        @if (session('duplicates'))
            <label class="check"><input type="checkbox" name="confirm_duplicate" value="1" required><span>Conferi: é um paciente diferente</span></label>
        @endif
        <a class="btn" href="{{ $patient->exists ? route('patients.show', $patient) : route('patients.index') }}">Cancelar</a>
        <button class="btn btn-primary" type="submit">{{ $patient->exists ? 'Salvar alterações' : 'Cadastrar paciente' }}</button>
    </div>
</form>
@endsection
