@extends('layouts.app', ['title' => 'Lotes de faturamento'])

@php use App\Core\Support\Format; use App\Modules\Insurance\Models\Guide; @endphp

@section('content')
@include('insurance._tabs')
<div class="page-head">
    <div><h1>Lotes de faturamento (TISS)</h1><p>Monte o lote com as guias prontas, feche para gerar o XML TISS validado e envie no portal da operadora. Depois, registre o retorno (pagamento e glosas).</p></div>
</div>

<section class="card mb-2">
    <div class="card__head"><h2>Guias prontas para faturar</h2></div>
    <div class="card__body stack">
        @forelse ($ready as $key => $guides)
            @php [$insurerId, $branchId, $type] = explode('|', $key); @endphp
            <form method="post" action="{{ route('batches.store') }}" class="stack">
                @csrf
                <input type="hidden" name="insurer_id" value="{{ $insurerId }}"><input type="hidden" name="branch_id" value="{{ $branchId }}"><input type="hidden" name="guide_type" value="{{ $type }}">
                <div class="spread"><h3>{{ $insurers[$insurerId]->name ?? '—' }} · {{ $branches[$branchId]->name ?? '—' }} · {{ Guide::TYPES[$type] }}</h3>
                    <button class="btn btn-primary btn-sm" type="submit">Montar lote com as selecionadas</button></div>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th></th><th>Guia</th><th>Atendimento</th><th>Paciente</th><th>Médico</th><th class="t-right">Valor</th></tr></thead>
                    <tbody>
                    @foreach ($guides as $i => $g)
                        <tr><td><input type="checkbox" name="guide_ids[]" value="{{ $g->id }}" @checked($i < 100) aria-label="Incluir guia {{ $g->number }}"></td>
                            <td><a class="mono" href="{{ route('guides.show', $g) }}">{{ $g->number }}</a></td><td>{{ $g->attendance_date->format('d/m/Y') }}</td>
                            <td>{{ $g->patient->displayName() }}</td><td>{{ $g->doctor->displayName() }}</td><td class="t-right">{{ Format::money($g->total_cents) }}</td></tr>
                    @endforeach
                    </tbody>
                </table></div>
            </form>
        @empty
            <p class="small muted">Nenhuma guia pronta. Confira as guias em <a href="{{ route('guides.index') }}">Guias</a> e marque como "pronta para faturar".</p>
        @endforelse
    </div>
</section>

<section class="card">
    <div class="card__head"><h2>Lotes</h2></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Lote</th><th>Convênio</th><th>Unidade</th><th>Tipo</th><th class="t-right">Guias</th><th class="t-right">Faturado</th><th class="t-right">Pago</th><th class="t-right">Glosa</th><th>Situação</th></tr></thead>
        <tbody>
        @forelse ($batches as $b)
            <tr>
                <td><a class="mono" href="{{ route('batches.show', $b) }}">{{ $b->number }}</a><div class="small muted">{{ $b->competence }}</div></td>
                <td>{{ $b->insurer->name }}</td><td>{{ $b->branch->name }}</td><td>{{ $b->guide_type === 'consulta' ? 'Consulta' : 'SP/SADT' }}</td>
                <td class="t-right">{{ $b->guides_count }}</td><td class="t-right">{{ Format::money($b->total_cents) }}</td>
                <td class="t-right">{{ Format::money($b->paid_cents) }}</td><td class="t-right">{{ Format::money($b->glosa_cents) }}</td>
                <td><span class="badge {{ ['open' => 'badge-warning', 'closed' => 'badge-info', 'partial' => 'badge-warning', 'paid' => 'badge-success'][$b->status] ?? '' }}">{{ $b->statusLabel() }}</span></td>
            </tr>
        @empty
            <tr><td colspan="9" class="empty">Nenhum lote.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    @include('partials.pagination', ['paginator' => $batches])
</section>
@endsection
