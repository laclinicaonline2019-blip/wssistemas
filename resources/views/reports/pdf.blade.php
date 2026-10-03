<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><title>{{ $report->title }}</title>
<style>
    @page { margin: 18mm 12mm 16mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #111; }
    h1 { font-size: 13pt; margin: 0 0 2mm; }
    .meta { color: #555; margin-bottom: 4mm; }
    .summary { margin-bottom: 4mm; }
    .summary span { display: inline-block; margin-right: 6mm; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; border-bottom: 1px solid #333; padding: 1.2mm 1mm; font-size: 8pt; }
    td { border-bottom: 1px solid #ddd; padding: 1mm; }
    .num { text-align: right; white-space: nowrap; }
    tr.total td { font-weight: bold; border-top: 1px solid #333; }
    .foot { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7pt; color: #777; }
</style></head>
<body>
    <h1>{{ $report->title }}</h1>
    <div class="meta">{{ $meta['clinic'] }} · {{ \Carbon\CarbonImmutable::parse($meta['from'])->format('d/m/Y') }} a {{ \Carbon\CarbonImmutable::parse($meta['to'])->format('d/m/Y') }}
        @if ($meta['branch']) · Unidade: {{ $meta['branch'] }}@endif @if ($meta['doctor']) · Médico: {{ $meta['doctor'] }}@endif</div>
    @if ($report->summary)<div class="summary">@foreach ($report->summary as $l => $v)<span><strong>{{ $l }}:</strong> {{ $v }}</span>@endforeach</div>@endif
    <table>
        <thead><tr>@foreach ($report->columns as $c)<th class="{{ in_array($c[1], ['money', 'int', 'pct'], true) ? 'num' : '' }}">{{ $c[0] }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach (array_slice($report->rows, 0, $max) as $row)
            <tr>@foreach ($report->columns as $k => $c)<td class="{{ in_array($c[1], ['money', 'int', 'pct'], true) ? 'num' : '' }}">{{ $cell($row[$k] ?? null, $c[1]) }}</td>@endforeach</tr>
        @endforeach
        @if ($report->totals)<tr class="total">@foreach ($report->columns as $k => $c)<td class="{{ in_array($c[1], ['money', 'int', 'pct'], true) ? 'num' : '' }}">{{ $cell($report->totals[$k] ?? null, $c[1]) }}</td>@endforeach</tr>@endif
        </tbody>
    </table>
    @if (count($report->rows) > $max)<p>Mostrando {{ $max }} de {{ count($report->rows) }} linhas — use a exportação em Excel para o relatório completo.</p>@endif
    @if ($report->note)<p class="meta">{{ $report->note }}</p>@endif
    <div class="foot">Gerado em {{ now('America/Sao_Paulo')->format('d/m/Y H:i') }} por {{ $meta['user'] }} · aivexaclinica</div>
</body></html>
