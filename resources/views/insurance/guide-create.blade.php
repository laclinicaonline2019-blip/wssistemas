@extends('layouts.app', ['title' => 'Nova guia'])

@php use App\Modules\Insurance\Models\Guide; @endphp

@section('content')
@include('insurance._tabs')
<div class="page-head">
    <div><h1>Guia avulsa</h1><p>Para atendimento feito sem agendamento (ex.: exame realizado na clínica). Consultas agendadas geram a guia sozinhas na chegada.</p></div>
    <a class="btn" href="{{ route('guides.index') }}">Voltar</a>
</div>

<section class="card">
    <div class="card__body">
        @if (! $patient)
            @include('insurance._patient-pick')
        @elseif ($patient->insurances->whereNotNull('insurer_id')->isEmpty())
            <p>{{ $patient->displayName() }} não tem carteirinha de um convênio cadastrado. <a href="{{ route('patients.edit', $patient) }}">Editar cadastro do paciente</a></p>
        @else
            <form method="post" action="{{ route('guides.store') }}" class="form-grid">
                @csrf
                <input type="hidden" name="patient_id" value="{{ $patient->id }}">
                <p class="col-12"><strong>{{ $patient->displayName() }}</strong> · prontuário #{{ $patient->record_number }} · <a href="{{ route('guides.create') }}">trocar</a></p>
                <div class="field col-6"><label for="g-ins">Carteirinha</label>
                    <select id="g-ins" name="patient_insurance_id" class="input" required>@foreach ($patient->insurances->whereNotNull('insurer_id') as $i)<option value="{{ $i->id }}">{{ $i->insurer_name }} · {{ $i->card_number }}{{ $i->valid_until ? ' · validade '.$i->valid_until->format('d/m/Y') : '' }}</option>@endforeach</select></div>
                <div class="field col-6"><label for="g-type">Tipo de guia</label><select id="g-type" name="guide_type" class="input">@foreach (Guide::TYPES as $k => $l)<option value="{{ $k }}" @selected(old('guide_type', 'sp_sadt') === $k)>{{ $l }}</option>@endforeach</select></div>
                <div class="field col-4"><label for="g-br">Unidade</label><select id="g-br" name="branch_id" class="input">@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select></div>
                <div class="field col-4"><label for="g-doc">Médico executante</label><select id="g-doc" name="doctor_id" class="input" required>@foreach ($doctors as $d)<option value="{{ $d->id }}">{{ $d->displayName() }}</option>@endforeach</select></div>
                <x-field name="attendance_date" label="Data do atendimento" type="date" col="col-4" :value="now('America/Sao_Paulo')->toDateString()" required />
                <div class="col-12 form-actions"><button class="btn btn-primary" type="submit">Criar guia</button></div>
            </form>
        @endif
    </div>
</section>
@endsection
