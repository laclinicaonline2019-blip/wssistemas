@extends('layouts.app', ['title' => $document->typeLabel().' '.$document->displayNumber()])

@php $tz = 'America/Sao_Paulo'; @endphp

@section('content')
<div class="page-head">
    <div>
        <h1>{{ $document->typeLabel() }} nº {{ $document->displayNumber() }}</h1>
        <p><a href="{{ route('patients.show', $document->patient) }}">{{ $document->patient->displayName() }}</a> · {{ $document->doctor->displayName() }}
            · emitido em {{ $document->issued_at->timezone($tz)->format('d/m/Y H:i') }}
            @if ($document->isCancelled())<span class="badge badge-danger">cancelado</span>@else<span class="badge badge-success">válido</span>@endif
            @unless ($intact)<span class="badge badge-danger">FALHA DE INTEGRIDADE</span>@endunless
        </p>
    </div>
    <div class="row">
        @if ($document->encounter_id)<a class="btn" href="{{ route('encounters.show', $document->encounter_id) }}">Atendimento</a>@endif
        <a class="btn" href="{{ route('documents.index', ['patient_id' => $document->patient_id]) }}">Documentos do paciente</a>
    </div>
</div>

@if ($group->count() > 1)
    <div class="alert alert-info">
        Emitidos juntos (separados conforme a legislação):
        @foreach ($group as $g)
            <a href="{{ route('documents.show', $g) }}" class="{{ $g->is($document) ? 'strong' : '' }}">{{ $g->typeLabel() }} {{ $g->displayNumber() }}</a>@if (! $loop->last) · @endif
        @endforeach
        @if ($group->where('status', 'issued')->isNotEmpty())
            — <a class="btn btn-sm btn-primary" href="{{ route('documents.print_group', [$document->group_id, 'format' => 'a4']) }}" target="_blank" rel="noopener">Imprimir todos (A4)</a>
        @endif
    </div>
@endif

<div class="encounter-layout">
    <section class="card">
        <iframe class="doc-preview" src="{{ route('documents.print', [$document, 'format' => 'a4', 'preview' => 1]) }}" title="Pré-visualização do documento"></iframe>
    </section>

    <aside class="stack">
        @unless ($document->isCancelled())
            <section class="card">
                <div class="card__head"><h2>Imprimir</h2></div>
                <div class="card__body stack">
                    <a class="btn btn-primary" href="{{ route('documents.print', [$document, 'format' => 'a4']) }}" target="_blank" rel="noopener"><svg><use href="#i-printer"/></svg>Imprimir A4</a>
                    <a class="btn" href="{{ route('documents.print', [$document, 'format' => 'a5']) }}" target="_blank" rel="noopener"><svg><use href="#i-printer"/></svg>Imprimir A5</a>
                    @if ($document->allowsThermal())
                        <a class="btn" href="{{ route('documents.print', [$document, 'format' => 'thermal']) }}" target="_blank" rel="noopener"><svg><use href="#i-printer"/></svg>Impressora térmica</a>
                    @endif
                    <a class="btn" href="{{ route('documents.pdf', $document) }}" target="_blank" rel="noopener"><svg><use href="#i-file"/></svg>PDF</a>
                    @if ($document->type === 'special_prescription')<p class="help">Sai em 2 vias (farmácia e paciente).</p>@endif
                    @if ($document->type === 'notification_record')<p class="help">Registro interno: a dispensação exige a Notificação de Receita oficial preenchida à mão.</p>@endif
                </div>
            </section>
        @endunless

        <section class="card">
            <div class="card__head"><h2>Autenticidade</h2></div>
            <div class="card__body">
                <dl class="dl">
                    <dt>Código</dt><dd class="mono">{{ $document->formattedCode() }}</dd>
                    <dt>Validação</dt><dd><a href="{{ route('documents.validate', $document->formattedCode()) }}" target="_blank" rel="noopener">página pública</a></dd>
                    @if ($document->valid_until)<dt>Validade</dt><dd>até {{ $document->valid_until->format('d/m/Y') }}</dd>@endif
                    <dt>Impressões</dt><dd>{{ $document->print_count }}{{ $document->last_printed_at ? ' · última '.$document->last_printed_at->timezone($tz)->format('d/m H:i') : '' }}</dd>
                    <dt>Emitido por</dt><dd>{{ $document->issuer?->name }}</dd>
                    <dt>Assinatura</dt><dd>De próprio punho (assinatura digital ICP-Brasil: integração prevista)</dd>
                </dl>
            </div>
        </section>

        @if ($document->isCancelled())
            <section class="card danger-zone"><div class="card__body small">
                <strong>Cancelado</strong> em {{ $document->cancelled_at->timezone($tz)->format('d/m/Y H:i') }} por {{ $document->canceller?->name }}.<br>Motivo: {{ $document->cancel_reason }}
            </div></section>
        @elseif ($canCancel)
            <section class="card danger-zone">
                <div class="card__head"><h2>Cancelar documento</h2></div>
                <form method="post" action="{{ route('documents.cancel', $document) }}" class="card__body stack" data-confirm="Cancelar este documento? A validação pública passará a indicar o cancelamento.">
                    @csrf
                    <label class="sr-only" for="c-reason">Motivo</label>
                    <input id="c-reason" name="reason" class="input @error('reason') is-invalid @enderror" minlength="10" maxlength="500" required placeholder="Motivo (ex.: posologia incorreta, reemitida)">
                    @error('reason')<div class="field-error">{{ $message }}</div>@enderror
                    <div><button class="btn btn-danger" type="submit">Cancelar documento</button></div>
                </form>
            </section>
        @endif
    </aside>
</div>
@endsection
