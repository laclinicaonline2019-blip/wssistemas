@extends('layouts.app', ['title' => 'Triagem — '.$patient->displayName()])

@php use App\Modules\Clinical\Models\Triage; @endphp

@section('content')
<div class="page-head">
    <div><h1>Triagem</h1><p>{{ $patient->displayName() }} · <span class="record-no">#{{ $patient->record_number }}</span>@if ($patient->age() !== null) · {{ $patient->age() }} anos @endif</p></div>
    <a class="btn" href="{{ route('triage.index') }}">Voltar</a>
</div>

@if ($allergies->isNotEmpty())
    <div class="alert alert-error"><strong>ALERGIAS:</strong> {{ $allergies->map(fn ($a) => $a->substance)->implode('; ') }}</div>
@endif

<form method="post" action="{{ route('triage.store') }}" class="card">
    @csrf
    <input type="hidden" name="patient_id" value="{{ $patient->id }}">
    @if ($appointment)<input type="hidden" name="appointment_id" value="{{ $appointment->id }}">@endif
    <div class="card__body form-grid">
        <x-field name="bp_systolic" label="PA sistólica (mmHg)" type="number" col="col-3" min="40" max="300" />
        <x-field name="bp_diastolic" label="PA diastólica (mmHg)" type="number" col="col-3" min="20" max="200" />
        <x-field name="heart_rate" label="FC (bpm)" type="number" col="col-3" min="20" max="250" />
        <x-field name="respiratory_rate" label="FR (irpm)" type="number" col="col-3" min="4" max="80" />
        <x-field name="temperature" label="Temperatura (°C)" col="col-3" inputmode="decimal" placeholder="36,5" />
        <x-field name="spo2" label="SpO₂ (%)" type="number" col="col-3" min="40" max="100" />
        <x-field name="weight_kg" label="Peso (kg)" col="col-3" inputmode="decimal" />
        <x-field name="height_cm" label="Altura (cm)" type="number" col="col-3" min="20" max="250" />
        <x-field name="glucose" label="Glicemia capilar (mg/dL)" type="number" col="col-3" min="10" max="1000" />
        <x-field name="pain_scale" label="Dor (0–10)" type="number" col="col-3" min="0" max="10" />
        <div class="field col-6">
            <label for="f-risk">Classificação de risco</label>
            <select id="f-risk" name="risk" class="input"><option value="">Não classificado</option>
                @foreach (Triage::RISKS as $k => $l)<option value="{{ $k }}" @selected(old('risk') === $k)>{{ $l }}</option>@endforeach</select>
        </div>
        <div class="field col-12">
            <label for="f-cc">Queixa principal</label>
            <input id="f-cc" name="chief_complaint" class="input" maxlength="500" value="{{ old('chief_complaint') }}">
        </div>
        <div class="field col-12">
            <label for="f-notes">Observações</label>
            <textarea id="f-notes" name="notes" class="input" rows="3" maxlength="2000">{{ old('notes') }}</textarea>
        </div>
        <p class="help col-12">A triagem é registrada de forma definitiva (não pode ser editada). Para corrigir, registre uma nova triagem. Os sinais vitais são copiados para o atendimento do médico.</p>
    </div>
    <div class="card__body form-actions"><button class="btn btn-primary" type="submit">Registrar triagem</button></div>
</form>
@endsection
