@extends('layouts.app', ['title' => 'Documentos'])

@php use App\Modules\Documents\Models\MedicalDocument; $tz = 'America/Sao_Paulo'; @endphp

@section('content')
<div class="page-head">
    <div><h1>Documentos{{ $patient ? ' — '.$patient->displayName() : '' }}</h1><p>Receitas, atestados, solicitações de exames e relatórios emitidos. Reimpressões ficam registradas.</p></div>
    <form method="get" class="row">
        @if ($patient)<input type="hidden" name="patient_id" value="{{ $patient->id }}">@endif
        <label class="sr-only" for="dt">Tipo</label>
        <select id="dt" name="type" class="input w-auto" data-autosubmit><option value="">Todos os tipos</option>
            @foreach ($types as $t)<option value="{{ $t }}" @selected(request('type') === $t)>{{ MedicalDocument::TYPES[$t] }}</option>@endforeach</select>
        @if ($patient)<a class="btn" href="{{ route('documents.index') }}">Todos os pacientes</a>@endif
    </form>
</div>

<section class="card">
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Nº</th><th>Emissão</th><th>Documento</th><th>Paciente</th><th class="hide-sm">Médico</th><th>Situação</th><th></th></tr></thead>
        <tbody>
        @forelse ($docs as $d)
            <tr class="{{ $d->isCancelled() ? 'muted' : '' }}">
                <td class="mono small">{{ $d->displayNumber() }}</td>
                <td class="small nowrap">{{ $d->issued_at->timezone($tz)->format('d/m/Y H:i') }}</td>
                <td>{{ $d->typeLabel() }}</td>
                <td>{{ $d->patient->displayName() }} <span class="record-no small">#{{ $d->patient->record_number }}</span></td>
                <td class="hide-sm small">{{ $d->doctor->displayName() }}</td>
                <td>@if ($d->isCancelled())<span class="badge badge-danger">cancelado</span>@else<span class="badge badge-success">válido</span>@endif
                    @if ($d->print_count)<div class="small muted">{{ $d->print_count }} impressão(ões)</div>@endif</td>
                <td class="actions"><a class="btn btn-sm" href="{{ route('documents.show', $d) }}">Abrir</a></td>
            </tr>
        @empty
            <tr><td colspan="7" class="empty">Nenhum documento emitido.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $docs->links() }}
</section>
@endsection
