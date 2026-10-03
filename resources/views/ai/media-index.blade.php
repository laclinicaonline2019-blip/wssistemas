@extends('layouts.app', ['title' => 'Documentos recebidos'])

@php use App\Modules\Ai\Models\AiMedia; @endphp

@section('content')
<div class="page-head">
    <div><h1>Documentos recebidos</h1><p>Fotos e PDFs enviados pelos pacientes no WhatsApp (ou lidos da ficha) com leitura automática pela IA. <strong>Nada é usado sem conferência</strong>: confira, anexe à ficha ou descarte.</p></div>
    <form method="get" class="row">
        <label class="sr-only" for="fr">Situação</label>
        <select id="fr" name="review" class="input w-auto">@foreach (AiMedia::REVIEW as $k => $l)<option value="{{ $k }}" @selected($review === $k)>{{ $l }}{{ $k === 'pending' ? ' ('.$pendingCount.')' : '' }}</option>@endforeach</select>
        <label class="sr-only" for="ft">Tipo</label>
        <select id="ft" name="type" class="input w-auto"><option value="">Todos os tipos</option>@foreach (AiMedia::DOC_TYPES as $k => $l)<option value="{{ $k }}" @selected($type === $k)>{{ $l }}</option>@endforeach</select>
        <button class="btn" type="submit">Filtrar</button>
    </form>
</div>
<section class="card">
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Recebido</th><th>Paciente / contato</th><th>Origem</th><th>Leitura da IA</th><th>Situação</th><th></th></tr></thead>
        <tbody>
        @forelse ($items as $m)
            <tr>
                <td class="nowrap small">{{ $m->created_at->timezone('America/Sao_Paulo')->format('d/m H:i') }}</td>
                <td>{{ $m->patient?->displayName() ?? $m->thread?->contact_name ?? ($m->thread ? '+'.$m->thread->phone : '—') }}
                    @if ($m->patient)<span class="small muted">#{{ $m->patient->record_number }}</span>@endif</td>
                <td class="small">{{ $m->source === 'whatsapp' ? 'WhatsApp' : 'Ficha (upload)' }} · {{ AiMedia::KINDS[$m->kind] }}</td>
                <td>@if ($m->status === 'processed')<span class="badge {{ $m->doc_type === 'payment_receipt' ? 'badge-warning' : 'badge-info' }}">{{ $m->docTypeLabel() }}</span>
                        <div class="small muted">{{ \Illuminate\Support\Str::limit($m->extraction['summary'] ?? '', 90) }}</div>
                    @elseif ($m->status === 'failed')<span class="badge badge-danger">não lido</span><div class="small muted">{{ \Illuminate\Support\Str::limit($m->error, 90) }}</div>
                    @else<span class="badge">{{ $m->status === 'skipped' ? 'leitura desligada' : 'processando' }}</span>@endif</td>
                <td><span class="badge {{ ['verified' => 'badge-success', 'discarded' => ''][$m->review_status] ?? 'badge-warning' }}">{{ $m->reviewLabel() }}</span></td>
                <td class="actions"><a class="btn btn-sm" href="{{ route('ai.media.show', $m) }}">Abrir</a></td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty">Nada por aqui.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    @include('partials.pagination', ['paginator' => $items])
</section>
@endsection
