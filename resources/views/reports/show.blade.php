@extends('layouts.app', ['title' => $report->title])

@section('content')
<div class="page-head">
    <div><h1>{{ $report->title }}</h1><p>{{ $info[3] }}</p></div>
    <div class="row">
        @foreach (['pdf' => 'PDF', 'xlsx' => 'Excel', 'csv' => 'CSV'] as $fmt => $label)
            <a class="btn btn-sm" href="{{ route('reports.show', [$key] + array_filter($f) + ['format' => $fmt]) }}">{{ $label }}</a>
        @endforeach
        <a class="btn btn-sm" href="{{ route('reports.index') }}">Relatórios</a>
    </div>
</div>

<form method="get" class="card mb-2"><div class="card__body form-grid">
    <x-field name="from" label="De" type="date" col="col-3" :value="$f['from']" />
    <x-field name="to" label="Até" type="date" col="col-3" :value="$f['to']" />
    <div class="field col-3"><label for="rb">Unidade</label><select id="rb" name="branch_id" class="input"><option value="">Todas</option>@foreach ($branches as $b)<option value="{{ $b->id }}" @selected(($f['branch_id'] ?? null) === $b->id)>{{ $b->name }}</option>@endforeach</select></div>
    @if (! in_array($key, ['cash_by_category', 'receipts_by_method'], true))
        <div class="field col-3"><label for="rd">Médico</label><select id="rd" name="doctor_id" class="input"><option value="">Todos</option>@foreach ($doctors as $d)<option value="{{ $d->id }}" @selected(($f['doctor_id'] ?? null) === $d->id)>{{ $d->displayName() }}</option>@endforeach</select></div>
    @endif
    <div class="col-12"><button class="btn btn-primary" type="submit">Atualizar</button></div>
</div></form>

@if ($report->summary)
    <div class="grid grid-4 mb-2">
        @foreach ($report->summary as $label => $value)
            <div class="card kpi"><div class="kpi__label">{{ $label }}</div><div class="kpi__value">{{ $value }}</div><div class="kpi__hint">&nbsp;</div></div>
        @endforeach
    </div>
@endif
@if ($report->note)<p class="help">{{ $report->note }}</p>@endif
@if ($report->truncated)<div class="alert alert-warning">Mostrando as primeiras {{ number_format(count($report->rows), 0, ',', '.') }} linhas. Reduza o período.</div>@endif

<section class="card">
    <div class="table-wrap"><table class="table">
        <thead><tr>@foreach ($report->columns as $c)<th class="{{ in_array($c[1], ['money', 'int', 'pct'], true) ? 'num' : '' }}">{{ $c[0] }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse (array_slice($report->rows, 0, 500) as $row)
            <tr>@foreach ($report->columns as $k => $c)<td class="{{ in_array($c[1], ['money', 'int', 'pct'], true) ? 'num nowrap' : '' }}">{{ $exporter->display($row[$k] ?? null, $c[1]) }}</td>@endforeach</tr>
        @empty
            <tr><td colspan="{{ count($report->columns) }}" class="empty">Sem dados no período.</td></tr>
        @endforelse
        @if ($report->totals)
            <tr class="strong">@foreach ($report->columns as $k => $c)<td class="{{ in_array($c[1], ['money', 'int', 'pct'], true) ? 'num nowrap' : '' }}">{{ $exporter->display($report->totals[$k] ?? null, $c[1]) }}</td>@endforeach</tr>
        @endif
        </tbody>
    </table></div>
    @if (count($report->rows) > 500)<p class="card__body help">Tela mostra 500 linhas; exporte para ver todas ({{ number_format(count($report->rows), 0, ',', '.') }}).</p>@endif
</section>
@endsection
