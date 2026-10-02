@extends('layouts.app', ['title' => 'Guias de convênio'])

@php use App\Core\Support\Format; use App\Modules\Insurance\Models\Guide; @endphp

@section('content')
@include('insurance._tabs')
<div class="page-head">
    <div><h1>Guias de convênio</h1><p>Geradas na chegada do paciente agendado por convênio, ou avulsas. Confira, marque como pronta e fature em lote.</p></div>
    <a class="btn btn-primary" href="{{ route('guides.create') }}"><svg><use href="#i-plus"/></svg>Guia avulsa</a>
</div>

<form method="get" class="row mb-2">
    <label class="sr-only" for="gs">Situação</label>
    <select id="gs" name="status" class="input w-auto">
        @foreach (['open' => 'A conferir / prontas', 'draft' => 'Rascunho', 'ready' => 'Prontas para faturar', 'billed' => 'Faturadas (aguardando)', 'glosa' => 'Com glosa em aberto', 'paid' => 'Pagas', 'partial' => 'Pagas com glosa', 'denied' => 'Glosadas', 'cancelled' => 'Canceladas', 'all' => 'Todas'] as $k => $l)
            <option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach
    </select>
    <label class="sr-only" for="gi">Convênio</label>
    <select id="gi" name="insurer_id" class="input w-auto"><option value="">Todos os convênios</option>@foreach ($insurers as $i)<option value="{{ $i->id }}" @selected($insurerId === $i->id)>{{ $i->name }}</option>@endforeach</select>
    <label class="sr-only" for="gq">Busca</label><input id="gq" name="q" value="{{ request('q') }}" class="input w-auto" placeholder="Nº da guia ou paciente">
    <button class="btn" type="submit">Filtrar</button>
</form>

<section class="card">
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Guia</th><th>Atendimento</th><th>Paciente</th><th>Convênio</th><th>Médico</th><th class="t-right">Valor</th><th class="t-right">Pago</th><th>Situação</th></tr></thead>
        <tbody>
        @forelse ($guides as $g)
            <tr>
                <td><a class="mono" href="{{ route('guides.show', $g) }}">{{ $g->number }}</a><div class="small muted">{{ $g->guide_type === 'consulta' ? 'Consulta' : 'SP/SADT' }}{{ $g->batch ? ' · lote '.$g->batch->number : '' }}</div></td>
                <td class="nowrap">{{ $g->attendance_date->format('d/m/Y') }}</td>
                <td>{{ $g->patient->displayName() }}</td>
                <td>{{ $g->insurer->name }}</td>
                <td>{{ $g->doctor->displayName() }}</td>
                <td class="t-right">{{ Format::money($g->total_cents) }}</td>
                <td class="t-right">{{ in_array($g->status, ['paid', 'partial', 'denied'], true) ? Format::money($g->paid_cents) : '—' }}</td>
                <td>@include('insurance._guide-badge', ['g' => $g])</td>
            </tr>
        @empty
            <tr><td colspan="8" class="empty">Nenhuma guia.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    @include('partials.pagination', ['paginator' => $guides])
</section>
@endsection
