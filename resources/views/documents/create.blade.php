@extends('layouts.app', ['title' => \App\Modules\Documents\Models\MedicalDocument::TYPES[$type].' — '.$patient->displayName()])

@php
    use App\Modules\Documents\Models\MedicalDocument;
    $titles = ['prescription' => 'Nova receita', 'certificate' => 'Novo atestado', 'exam_request' => 'Solicitação de exames', 'report' => 'Relatório / declaração / encaminhamento'];
    $tz = 'America/Sao_Paulo';
    $commonExams = ['Hemograma completo', 'Glicemia de jejum', 'Hemoglobina glicada (HbA1c)', 'Colesterol total e frações', 'Triglicerídeos', 'Creatinina',
        'Ureia', 'TGO (AST) e TGP (ALT)', 'TSH', 'T4 livre', 'Urina tipo 1 (EAS)', 'Urocultura com antibiograma', 'PSA total', '25-OH vitamina D',
        'Vitamina B12', 'Ferritina', 'Sódio e potássio', 'Proteína C reativa (PCR)', 'Eletrocardiograma (ECG)', 'Radiografia de tórax PA e perfil', 'Ultrassonografia de abdome total'];
    $oldItems = old('items', [[]]);
@endphp

@section('content')
<div class="page-head">
    <div>
        <h1>{{ $titles[$type] }}</h1>
        <p>{{ $patient->displayName() }} · <span class="record-no">#{{ $patient->record_number }}</span>@if ($patient->age() !== null) · {{ $patient->age() }} anos @endif
            @if ($encounter) · atendimento de {{ $encounter->started_at->timezone($tz)->format('d/m/Y H:i') }} @endif</p>
    </div>
    <a class="btn" href="{{ $encounter ? ($encounter->isDraft() ? route('encounters.edit', $encounter) : route('encounters.show', $encounter)) : route('patients.show', $patient) }}">Voltar</a>
</div>

@if ($allergies->isNotEmpty())
    <div class="alert alert-error" role="alert"><strong>ALERGIAS:</strong> {{ $allergies->map(fn ($a) => $a->substance.($a->reaction ? ' ('.$a->reaction.')' : ''))->implode('; ') }}</div>
@endif

<form method="post" action="{{ route('documents.store') }}" class="stack" @if ($type === 'prescription') data-rx-form data-med-url="{{ route('clinical.medications.search') }}" @endif>
    @csrf
    <input type="hidden" name="type" value="{{ $type }}">
    <input type="hidden" name="patient_id" value="{{ $patient->id }}">
    @if ($encounter)<input type="hidden" name="encounter_id" value="{{ $encounter->id }}">@endif

    @if ($type === 'prescription')
        <section class="card">
            <div class="card__body stack">
                <div class="lookup">
                    <label class="label" for="med-q">Adicionar medicamento da base</label>
                    <input id="med-q" class="input" placeholder="Digite o princípio ativo ou nome comercial (ex.: amoxicilina)" autocomplete="off" data-med-q>
                    <div class="lookup__list hidden" data-med-list></div>
                </div>
                <div><button type="button" class="btn btn-sm" data-rx-add>+ Item digitado manualmente</button></div>
                <p class="help">A receita é separada automaticamente conforme a Portaria 344/98: medicamentos livres → receita simples;
                    antimicrobianos e listas C1/C4/C5 → <strong>receita de controle especial em 2 vias</strong>;
                    listas A, B, C2 e C3 → exigem a <strong>Notificação de Receita oficial</strong> (talão), e aqui apenas se registra o número.</p>
            </div>
        </section>
        @error('items')<div class="alert alert-error">{{ $message }}</div>@enderror
        <div class="stack" data-rx-rows>
            @foreach ($oldItems as $i => $item)
                @if ($item !== [] || $loop->count === 1)
                    @include('documents._rx-row', ['i' => $i, 'item' => $item])
                @endif
            @endforeach
        </div>
        <template id="rx-template">@include('documents._rx-row', ['i' => '__I__', 'item' => []])</template>
        <section class="card"><div class="card__body">
            <label class="label" for="f-notes">Orientações gerais (opcional)</label>
            <textarea id="f-notes" name="notes" class="input" rows="3" maxlength="2000">{{ old('notes') }}</textarea>
        </div></section>
    @elseif ($type === 'certificate')
        <section class="card"><div class="card__body form-grid">
            <div class="field col-12">
                <span class="label">Tipo</span>
                <div class="row">
                    <label class="check"><input type="radio" name="subtype" value="leave" @checked(old('subtype', 'leave') === 'leave') data-toggle-group="cert"><span>Afastamento (dias)</span></label>
                    <label class="check"><input type="radio" name="subtype" value="attendance" @checked(old('subtype') === 'attendance') data-toggle-group="cert"><span>Comparecimento (horário)</span></label>
                </div>
            </div>
            <div class="col-12 form-grid" data-toggle-show="cert:leave">
                <x-field name="days" label="Dias de afastamento" type="number" min="1" max="365" col="col-4" :value="old('days', 1)" />
                <x-field name="start_date" label="A partir de" type="date" col="col-4" :value="old('start_date', now($tz)->toDateString())" />
            </div>
            <div class="col-12 form-grid" data-toggle-show="cert:attendance">
                <x-field name="start_time" label="Chegada" type="time" col="col-4" :value="old('start_time', $encounter?->started_at->timezone($tz)->format('H:i'))" />
                <x-field name="end_time" label="Saída" type="time" col="col-4" :value="old('end_time', now($tz)->format('H:i'))" />
            </div>
            <x-field name="purpose" label="Finalidade (opcional)" col="col-12" placeholder="ex.: apresentação ao empregador, justificativa escolar" maxlength="200" />
            @include('documents._cid-picker', ['label' => 'CID-10 (somente com autorização do paciente)'])
            <label class="check col-12"><input type="checkbox" name="cid_authorized" value="1" @checked(old('cid_authorized'))><span>O paciente autorizou expressamente a inclusão do CID no atestado (Res. CFM 1.658/2002).</span></label>
            <div class="field col-12"><label for="f-notes">Observações (opcional)</label><textarea id="f-notes" name="notes" class="input" rows="2" maxlength="500">{{ old('notes') }}</textarea></div>
        </div></section>
    @elseif ($type === 'exam_request')
        <section class="card"><div class="card__body form-grid">
            <div class="field col-12">
                <label for="f-exams">Exames (um por linha)</label>
                <textarea id="f-exams" name="exams_text" class="input" rows="8" required>{{ old('exams_text') }}</textarea>
                @error('exams')<div class="field-error">{{ $message }}</div>@enderror
                <div class="chips mt-1">@foreach ($commonExams as $e)<button type="button" class="btn btn-sm btn-ghost" data-append-line="#f-exams" data-value="{{ $e }}">{{ $e }}</button>@endforeach</div>
            </div>
            <div class="field col-12"><label for="f-ind">Indicação clínica</label><input id="f-ind" name="indication" class="input" maxlength="500" value="{{ old('indication') }}"></div>
            @include('documents._cid-picker')
            <label class="check col-12"><input type="checkbox" name="urgent" value="1" @checked(old('urgent'))><span>Urgente</span></label>
        </div></section>
    @else
        <section class="card"><div class="card__body form-grid">
            <div class="field col-4"><label for="f-sub">Tipo</label>
                <select id="f-sub" name="subtype" class="input">@foreach (MedicalDocument::REPORT_SUBTYPES as $k => $l)<option value="{{ $k }}" @selected(old('subtype') === $k)>{{ $l }}</option>@endforeach</select></div>
            <x-field name="title" label="Título (opcional)" col="col-8" maxlength="150" />
            <x-field name="recipient" label="Destinatário / encaminhar a (opcional)" col="col-12" maxlength="150" placeholder="ex.: Ao(à) cardiologista; À empresa X" />
            <div class="field col-12"><label for="f-body">Texto</label><textarea id="f-body" name="body" class="input" rows="12" maxlength="10000" required>{{ old('body') }}</textarea>
                @error('body')<div class="field-error">{{ $message }}</div>@enderror</div>
        </div></section>
    @endif

    <div class="form-actions">
        <span class="small muted">Depois de emitido, o documento não pode ser alterado — apenas cancelado com motivo.</span>
        <button class="btn btn-primary" type="submit">Emitir e imprimir</button>
    </div>
</form>
@endsection
