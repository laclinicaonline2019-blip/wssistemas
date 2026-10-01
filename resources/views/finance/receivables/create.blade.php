@extends('layouts.app', ['title' => 'Nova conta a receber'])

@section('content')
@include('finance._tabs')
<div class="page-head"><div><h1>Nova conta a receber</h1><p>Procedimentos avulsos, pacotes, exames ou outras receitas.</p></div>
    <a class="btn" href="{{ route('receivables.index') }}">Voltar</a></div>

<form method="post" action="{{ route('receivables.store') }}" class="card">
    @csrf
    <div class="card__body form-grid">
        @if (auth()->user()->hasPermission('paciente.visualizar') || auth()->user()->hasPermission('agenda.criar'))
        <div class="field col-12 lookup" data-patient-lookup="{{ route('agenda.patient_lookup') }}">
            <label for="patient-q">Paciente (opcional)</label>
            <input type="hidden" name="patient_id" id="patient_id" value="{{ old('patient_id', $patient?->id) }}">
            <div class="selected-patient {{ $patient ? '' : 'hidden' }}" id="patient-selected"><div><strong id="patient-selected-name">{{ $patient?->displayName() }}</strong> <span class="small muted" id="patient-selected-info">{{ $patient ? '#'.$patient->record_number : '' }}</span></div>
                <button type="button" class="btn btn-sm btn-ghost" data-patient-clear>Trocar</button></div>
            <input id="patient-q" class="input {{ $patient ? 'hidden' : '' }}" placeholder="Nome, CPF ou nº do prontuário" autocomplete="off">
            <div id="patient-results" class="lookup__list hidden"></div>
            <select id="patient_insurance_id" class="hidden" aria-hidden="true" tabindex="-1"></select><select class="hidden" data-payer aria-hidden="true" tabindex="-1"><option value="private">p</option></select><div id="insurance-field" class="hidden"></div>
        </div>
        @endif
        <x-field name="description" label="Descrição" col="col-8" maxlength="200" required />
        <x-field name="amount" label="Valor (R$)" col="col-4" mask="money" inputmode="numeric" required />
        <div class="field col-4"><label for="f-cat">Categoria</label>
            <select id="f-cat" name="category_id" class="input" required>@foreach ($categories as $c)<option value="{{ $c->id }}" @selected(old('category_id') === $c->id)>{{ $c->name }}</option>@endforeach</select></div>
        <div class="field col-4"><label for="f-br">Unidade</label>
            <select id="f-br" name="branch_id" class="input" required>@foreach ($branches as $b)<option value="{{ $b->id }}" @selected(old('branch_id') === $b->id)>{{ $b->name }}</option>@endforeach</select></div>
        <x-field name="due_date" label="Vencimento" type="date" col="col-4" :value="now('America/Sao_Paulo')->toDateString()" required />
        <x-field name="notes" label="Observações" col="col-12" maxlength="500" />
    </div>
    <div class="card__body form-actions"><button class="btn btn-primary" type="submit">Criar conta</button></div>
</form>
@endsection
