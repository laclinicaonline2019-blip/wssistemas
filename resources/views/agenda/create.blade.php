@extends('layouts.app', ['title' => 'Novo agendamento'])

@php $tz = $branch->timezone; $local = $start->setTimezone($tz); @endphp

@section('content')
<div class="page-head">
    <div><h1>{{ $overbook ? 'Encaixe' : 'Novo agendamento' }}</h1>
        <p>{{ $doctor->displayName() }} · {{ $branch->name }} · <strong>{{ $local->translatedFormat('l, d/m/Y') }} às {{ $local->format('H:i') }}</strong>
            @if ($overbook)<span class="badge badge-warning">encaixe</span>@endif</p></div>
    <a class="btn" href="{{ route('agenda.index', ['date' => $local->toDateString(), 'branch_id' => $branch->id]) }}">Voltar à agenda</a>
</div>

<form method="post" action="{{ route('agenda.store') }}" class="card" id="booking-form">
    @csrf
    <input type="hidden" name="doctor_id" value="{{ $doctor->id }}">
    <input type="hidden" name="branch_id" value="{{ $branch->id }}">
    <input type="hidden" name="starts_at" value="{{ $start->toIso8601String() }}">
    <input type="hidden" name="is_overbook" value="{{ $overbook ? 1 : 0 }}">
    <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::ulid()) }}">
    <div class="card__body form-grid">
        <div class="field col-12 lookup" data-patient-lookup="{{ route('agenda.patient_lookup') }}">
            <label for="patient-q">Paciente *</label>
            <input type="hidden" name="patient_id" id="patient_id" value="{{ old('patient_id', $patient?->id) }}">
            <div class="selected-patient {{ old('patient_id', $patient?->id) ? '' : 'hidden' }}" id="patient-selected">
                <strong id="patient-selected-name">{{ $patient?->displayName() }}</strong>
                <span class="small muted" id="patient-selected-info">{{ $patient ? '#'.$patient->record_number : '' }}</span>
                <button type="button" class="btn btn-ghost btn-sm" data-patient-clear>trocar</button>
            </div>
            <input id="patient-q" class="input {{ old('patient_id', $patient?->id) ? 'hidden' : '' }} @error('patient_id') is-invalid @enderror" placeholder="Digite nome, CPF, telefone ou nº do prontuário" autocomplete="off">
            <div class="lookup__list hidden" id="patient-results" role="listbox"></div>
            @error('patient_id')<div class="field-error">{{ $message }}</div>@enderror
            @if (auth()->user()->hasPermission('paciente.criar'))
                <div class="help">Paciente novo? <a href="{{ route('patients.create') }}" target="_blank" rel="noopener">Cadastrar</a> e depois buscar aqui.</div>
            @endif
        </div>

        <div class="field col-6"><label for="service_id">Tipo de atendimento</label>
            <select id="service_id" name="service_id" class="input">
                @forelse ($services as $s)
                    <option value="{{ $s->id }}" @selected(old('service_id') === $s->id)>{{ $s->name }} — {{ $s->priceFormatted() }}{{ $s->duration_minutes ? ' · '.$s->duration_minutes.' min' : '' }}{{ $s->accepts_insurance ? ' · aceita convênio' : '' }}</option>
                @empty
                    <option value="">Consulta (sem tipos cadastrados)</option>
                @endforelse
            </select></div>

        <div class="field col-3"><label for="payer_type">Pagamento</label>
            <select id="payer_type" name="payer_type" class="input" data-payer>
                <option value="private" @selected(old('payer_type') !== 'insurance')>Particular</option>
                <option value="insurance" @selected(old('payer_type') === 'insurance')>Convênio</option>
            </select></div>

        <div class="field col-3 {{ old('payer_type') === 'insurance' ? '' : 'hidden' }}" id="insurance-field"><label for="patient_insurance_id">Convênio do paciente</label>
            <select id="patient_insurance_id" name="patient_insurance_id" class="input">
                @foreach ($patient?->insurances ?? [] as $i)<option value="{{ $i->id }}">{{ $i->insurer_name }} · {{ $i->card_number }}</option>@endforeach
            </select>
            @error('patient_insurance_id')<div class="field-error">{{ $message }}</div>@enderror</div>

        <div class="field col-6"><label for="channel">Canal</label>
            <select id="channel" name="channel" class="input">
                @foreach (['reception' => 'Recepção (presencial)', 'phone' => 'Telefone', 'whatsapp' => 'WhatsApp'] as $k => $l)<option value="{{ $k }}" @selected(old('channel') === $k)>{{ $l }}</option>@endforeach
            </select></div>
        <div class="field col-12"><label for="notes">Observações</label>
            <input id="notes" name="notes" class="input" maxlength="500" value="{{ old('notes') }}" placeholder="Ex.: trazer exames anteriores"></div>
        <p class="col-12 help">O horário é revalidado ao salvar: se alguém ocupar este horário antes, você verá um aviso e nada é duplicado.</p>
        <div class="col-12 form-actions"><button class="btn btn-primary" type="submit">Confirmar agendamento</button></div>
    </div>
</form>
@endsection
