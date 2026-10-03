@extends('layouts.app', ['title' => 'Documento recebido'])

@php
    use App\Modules\Ai\Models\AiMedia;
    $e = $media->extraction ?? [];
    $who = $media->patient ?? $media->thread?->patient;
    $val = fn ($v) => ($v === null || $v === '') ? '—' : $v;
@endphp

@section('content')
<div class="page-head">
    <div><h1>{{ $media->docTypeLabel() ?? AiMedia::KINDS[$media->kind] }} <span class="badge {{ ['verified' => 'badge-success', 'discarded' => ''][$media->review_status] ?? 'badge-warning' }}">{{ $media->reviewLabel() }}</span></h1>
        <p>{{ $media->source === 'whatsapp' ? 'Recebido pelo WhatsApp' : 'Lido da ficha do paciente' }} em {{ $media->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}
            · {{ $who?->displayName() ?? $media->thread?->contact_name ?? ($media->thread ? '+'.$media->thread->phone : 'paciente não identificado') }}
            @if ($media->provider)· leitura: {{ $media->provider === 'mock' ? 'MOCK (simulada)' : $media->provider.' / '.$media->model }}@endif</p></div>
    <div class="row">
        @if ($media->thread)<a class="btn" href="{{ route('messaging.threads.show', $media->thread) }}">Conversa</a>@endif
        @if ($who)<a class="btn" href="{{ route('patients.show', $who) }}">Ficha do paciente</a>@endif
        <a class="btn" href="{{ route('ai.media.index') }}">Voltar</a>
    </div>
</div>

<div class="alert alert-warning"><strong>Leitura automática — NÃO VERIFICADA.</strong> Confira com o original. A IA só transcreve: não interpreta exames nem valida receitas.
    @if ($media->doc_type === 'payment_receipt') Comprovante <strong>não</strong> dá baixa em nada: confirme no extrato/gateway antes de qualquer lançamento.@endif</div>

<div class="grid grid-2">
    <section class="card">
        <div class="card__head"><h2>Original</h2>@if ($media->path)<a class="btn btn-sm" href="{{ route('ai.media.file', $media) }}">Baixar</a>@endif</div>
        <div class="card__body">
            @if (! $media->path)<p class="muted">Arquivo não disponível ({{ $media->error }}).</p>
            @elseif ($media->isImage())<img class="media-preview" src="{{ route('ai.media.file', [$media, 'inline' => 1]) }}" alt="Imagem enviada pelo paciente">
            @elseif ($media->kind === 'audio')<audio controls src="{{ route('ai.media.file', [$media, 'inline' => 1]) }}"></audio>
            @else<a class="btn" href="{{ route('ai.media.file', [$media, 'inline' => 1]) }}" target="_blank" rel="noopener">Abrir PDF</a>@endif
            @if ($media->caption)<p class="small"><strong>Legenda do paciente:</strong> {{ $media->caption }}</p>@endif
        </div>
    </section>

    <section class="card">
        <div class="card__head"><h2>Dados lidos pela IA</h2></div>
        <div class="card__body stack small">
            @if ($media->status === 'failed')<div class="alert alert-error">Não foi possível ler: {{ $media->error }}</div>@endif
            @if ($media->kind === 'audio')<p><strong>Transcrição:</strong> {{ $val($media->transcript) }}</p>@endif
            @if ($media->status === 'processed' && $media->kind !== 'audio')
                <p>{{ $e['summary'] ?? '' }}</p>
                <dl class="dl">
                    <dt>Paciente no documento</dt><dd>{{ $val($e['patient_name'] ?? '') }}</dd>
                    <dt>Data</dt><dd>{{ $val($e['document_date'] ?? '') }}</dd>
                    <dt>Profissional</dt><dd>{{ $val($e['professional_name'] ?? '') }} {{ ($e['professional_registry'] ?? '') !== '' ? '('.$e['professional_registry'].')' : '' }}</dd>
                    <dt>Legibilidade</dt><dd>{{ ['good' => 'boa', 'partial' => 'parcial', 'poor' => 'ruim'][$e['legibility'] ?? ''] ?? '—' }}</dd>
                </dl>
                @if (! empty($e['medications']))
                    <h3 class="mb-0">Medicamentos (transcritos)</h3>
                    <div class="table-wrap"><table class="table"><thead><tr><th>Nome</th><th>Concentração</th><th>Forma</th><th>Posologia</th><th>Qtd.</th></tr></thead><tbody>
                        @foreach ($e['medications'] as $m)<tr><td>{{ $val($m['name'] ?? '') }}</td><td>{{ $val($m['concentration'] ?? '') }}</td><td>{{ $val($m['form'] ?? '') }}</td><td>{{ $val($m['dosage_instructions'] ?? '') }}</td><td>{{ $val($m['quantity'] ?? '') }}</td></tr>@endforeach
                    </tbody></table></div>
                @endif
                @if (! empty($e['exams']))
                    <h3 class="mb-0">Exames solicitados</h3>
                    <ul class="list">@foreach ($e['exams'] as $x)<li>{{ $x['name'] ?? '' }} @if (($x['code'] ?? '') !== '')<span class="mono muted">{{ $x['code'] }}</span>@endif</li>@endforeach</ul>
                @endif
                @if ($media->doc_type === 'payment_receipt')
                    <h3 class="mb-0">Comprovante</h3>
                    <dl class="dl">@foreach (['amount' => 'Valor', 'paid_at' => 'Data/hora', 'payer_name' => 'Pagador', 'receiver_name' => 'Recebedor', 'method' => 'Forma', 'transaction_id' => 'Identificador'] as $k => $l)<dt>{{ $l }}</dt><dd>{{ $val($e['payment'][$k] ?? '') }}</dd>@endforeach</dl>
                @endif
                @if (! empty($e['uncertain_fields']))<p class="text-danger"><strong>Trechos incertos:</strong> {{ implode(', ', $e['uncertain_fields']) }}</p>@endif
                <details><summary>Texto transcrito</summary><pre class="prewrap small">{{ $e['raw_text'] ?? '' }}</pre></details>
            @endif
        </div>
    </section>
</div>

<div class="grid grid-2 mt-2">
    <section class="card">
        <div class="card__head"><h2>Conferência</h2></div>
        <div class="card__body stack">
            @if ($media->review_status !== 'pending')
                <p class="small">{{ $media->reviewLabel() }} por {{ $media->reviewer?->name ?? '—' }} em {{ $media->reviewed_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}@if ($media->review_notes) — {{ $media->review_notes }}@endif</p>
            @else
                <form method="post" action="{{ route('ai.media.verify', $media) }}" class="stack">@csrf
                    <label for="vn">Observação (opcional)</label><textarea id="vn" name="notes" class="input" rows="2" maxlength="1000" placeholder="Ex.: conferido com o original; dose da linha 2 corrigida na ficha"></textarea>
                    <button class="btn btn-primary" type="submit">Marcar como conferido</button></form>
                <form method="post" action="{{ route('ai.media.discard', $media) }}" class="row">@csrf
                    <label class="sr-only" for="dr">Motivo</label><input id="dr" name="reason" class="input" placeholder="Motivo do descarte (ex.: foto repetida)" required minlength="3" maxlength="1000">
                    <button class="btn" type="submit">Descartar</button></form>
            @endif
        </div>
    </section>
    @if ($media->kind !== 'audio' && $media->path)
        <section class="card">
            <div class="card__head"><h2>Ficha do paciente</h2></div>
            <div class="card__body">
                @if ($media->patient_file_id)
                    <p class="small">Arquivo na ficha: <strong>{{ $media->patientFile?->title }}</strong>.</p>
                @elseif (auth()->user()->hasPermission('documento.anexar'))
                    <form method="post" action="{{ route('ai.media.attach', $media) }}" class="form-grid">@csrf
                        @unless ($media->patient_id)<x-field name="record_number" label="Nº do prontuário do paciente" type="number" min="1" col="col-6" required help="O telefone não está vinculado a um único paciente." />@endunless
                        <div class="field col-6"><label for="at-c">Categoria</label>
                            <select id="at-c" name="category" class="input">@foreach ($categories as $k => $l)<option value="{{ $k }}" @selected($k === ($media->doc_type === 'exam_result' ? 'exam_result' : ($media->doc_type === 'identity_document' ? 'document' : 'other')))>{{ $l }}</option>@endforeach</select></div>
                        <x-field name="title" label="Título" col="col-12" :value="($media->docTypeLabel() ?? 'Documento').' (WhatsApp '.$media->created_at->timezone('America/Sao_Paulo')->format('d/m/Y').')'" required />
                        <div class="col-12"><button class="btn" type="submit">Anexar à ficha</button></div>
                    </form>
                @else
                    <p class="small muted">Sem permissão para anexar arquivos.</p>
                @endif
            </div>
        </section>
    @endif
</div>
@endsection
