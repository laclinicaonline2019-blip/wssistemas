@extends('layouts.app', ['title' => 'Prontuário — '.$encounter->patient->displayName()])

@php
    use App\Modules\Clinical\Models\Encounter;
    $tz = 'America/Sao_Paulo';
    $patient = $encounter->patient;
    $versions = $encounter->versions->sortByDesc('version');
@endphp

@section('content')
<div class="page-head">
    <div>
        <h1>{{ $patient->displayName() }}</h1>
        <p>Prontuário <span class="record-no">#{{ $patient->record_number }}</span>
            · atendimento de {{ $encounter->started_at->timezone($tz)->format('d/m/Y H:i') }} · {{ $encounter->doctor->displayName() }}
            @if ($encounter->doctor->crm) (CRM {{ $encounter->doctor->crm }}/{{ $encounter->doctor->crm_state }}) @endif
            · {{ $encounter->branch->name }}</p>
    </div>
    <div class="row">
        @if ($integrity)
            @if ($integrity['ok'])
                <span class="badge badge-success" title="Encadeamento HMAC verificado">Integridade verificada</span>
            @else
                <span class="badge badge-danger">FALHA DE INTEGRIDADE na versão {{ $integrity['broken_at'] }}</span>
            @endif
        @endif
        @if ($encounter->isDraft() && $isAuthor)
            <a class="btn btn-primary" href="{{ route('encounters.edit', $encounter) }}">Continuar atendimento</a>
        @elseif (! $encounter->isDraft() && $isAuthor)
            <a class="btn" href="{{ route('encounters.addendum', $encounter) }}">Registrar adendo</a>
        @endif
        <a class="btn" href="{{ route('patients.show', $patient) }}">Ficha do paciente</a>
    </div>
</div>

@if ($allergies->isNotEmpty())
    <div class="alert alert-error"><strong>ALERGIAS:</strong> {{ $allergies->map(fn ($a) => $a->substance)->implode('; ') }}</div>
@endif

@if ($encounter->isDraft())
    <div class="alert alert-warning">Atendimento em andamento (rascunho, ainda não assinado). O conteúdo só é exibido aqui após a finalização.</div>
@endif

@foreach ($versions as $v)
    <section class="card mb-2 {{ $loop->first ? '' : 'version-old' }}">
        <div class="card__head">
            <h2>{{ $v->kind === 'original' ? 'Registro original' : 'Adendo' }} · versão {{ $v->version }}
                @if ($loop->first && $versions->count() > 1)<span class="badge badge-primary">vigente</span>@endif</h2>
            <span class="small muted">{{ $v->created_at->timezone($tz)->format('d/m/Y H:i:s') }} · {{ $v->authorDoctor?->displayName() ?? $v->author?->name }}</span>
        </div>
        <div class="card__body">
            @if ($v->reason)<p class="alert alert-info small"><strong>Justificativa:</strong> {{ $v->reason }}</p>@endif
            <dl class="dl dl-clinical">
                @foreach (Encounter::SECTIONS as $key => $label)
                    @if (! empty($v->data[$key]))<dt>{{ $label }}</dt><dd class="prewrap">{{ $v->data[$key] }}</dd>@endif
                @endforeach
                @if (! empty($v->diagnoses))
                    <dt>Diagnósticos</dt>
                    <dd>@foreach ($v->diagnoses as $d)<div><strong>{{ $d['code'] }}</strong> {{ $d['description'] }} @if ($d['is_primary'])<span class="badge">principal</span>@endif{{ ! empty($d['notes']) ? ' — '.$d['notes'] : '' }}</div>@endforeach</dd>
                @endif
                @if (! empty($v->data['return_in_days']))<dt>Retorno</dt><dd>em {{ $v->data['return_in_days'] }} dias</dd>@endif
            </dl>
            <p class="small muted mono mt-1" title="Hash HMAC-SHA256 encadeado">#{{ substr($v->hash, 0, 16) }}…</p>
        </div>
    </section>
@endforeach

@if ($documents->isNotEmpty() || $isAuthor)
    <section class="card mb-2">
        <div class="card__head"><h2>Documentos emitidos</h2>
            @if ($isAuthor)<div class="row">
                @if (auth()->user()->hasPermission('receita.emitir'))<a class="btn btn-sm" href="{{ route('documents.create', ['type' => 'prescription', 'patient_id' => $patient->id, 'encounter_id' => $encounter->id]) }}">Receita</a>@endif
                @if (auth()->user()->hasPermission('atestado.emitir'))<a class="btn btn-sm" href="{{ route('documents.create', ['type' => 'certificate', 'patient_id' => $patient->id, 'encounter_id' => $encounter->id]) }}">Atestado</a>@endif
                @if (auth()->user()->hasPermission('exame.solicitar'))<a class="btn btn-sm" href="{{ route('documents.create', ['type' => 'exam_request', 'patient_id' => $patient->id, 'encounter_id' => $encounter->id]) }}">Exames</a>@endif
            </div>@endif
        </div>
        <div class="card__body">
            @forelse ($documents as $d)
                <div class="spread {{ $d->isCancelled() ? 'muted' : '' }}"><a href="{{ route('documents.show', $d) }}">{{ $d->typeLabel() }} {{ $d->displayNumber() }}</a>
                    <span class="small">{{ $d->issued_at->timezone($tz)->format('d/m/Y H:i') }} @if ($d->isCancelled())<span class="badge badge-danger">cancelado</span>@endif</span></div>
            @empty
                <p class="small muted">Nenhum documento emitido neste atendimento.</p>
            @endforelse
        </div>
    </section>
@endif

@if ($history->isNotEmpty())
    <section class="card">
        <div class="card__head"><h2>Outros atendimentos do paciente</h2></div>
        <div class="card__body"><ul class="timeline">
            @foreach ($history as $h)
                <li><a href="{{ route('encounters.show', $h) }}">{{ $h->started_at->timezone($tz)->format('d/m/Y') }}</a> · {{ $h->doctor->displayName() }}
                    @if ($h->diagnoses->isNotEmpty())<span class="small"> · {{ $h->diagnoses->where('version', $h->current_version)->pluck('code')->implode(', ') }}</span>@endif</li>
            @endforeach
        </ul></div>
    </section>
@endif
@endsection
