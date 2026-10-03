@php use App\Core\Support\Format; $d = $closing->data; $a = $d['appointments']; $s = $d['split']; $i = $d['insurance']; @endphp
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><title>Demonstrativo {{ $closing->periodLabel() }}</title>
<style>
    @page { margin: 16mm 14mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; color: #111; }
    h1 { font-size: 13pt; margin: 0; } h2 { font-size: 10.5pt; margin: 5mm 0 2mm; }
    .meta { color: #555; margin: 1mm 0 4mm; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; border-bottom: 1px solid #333; padding: 1mm; } td { border-bottom: 1px solid #ddd; padding: 1mm; }
    .num { text-align: right; white-space: nowrap; }
    .box td { border: 1px solid #ccc; padding: 2mm; width: 25%; vertical-align: top; }
    .box strong { font-size: 11pt; display: block; }
    .foot { margin-top: 6mm; font-size: 7.5pt; color: #555; }
</style></head>
<body>
    <h1>Demonstrativo médico × clínica — {{ $closing->periodLabel() }}</h1>
    <div class="meta">{{ $clinic }} · {{ $d['doctor']['name'] }} (CRM {{ $d['doctor']['crm'] }}) · versão {{ $closing->version }} · {{ $closing->statusLabel() }}</div>
    <table class="box"><tr>
        <td>Atendidos<strong>{{ $a['attended'] }}</strong>{{ $a['private'] }} particular · {{ $a['insurance'] }} convênio</td>
        <td>Faltas / cancelados<strong>{{ $a['no_show'] }} / {{ $a['cancelled'] }}</strong></td>
        <td>Parte do médico<strong>{{ Format::money($s['share']) }}</strong>sobre {{ Format::money($s['base']) }}</td>
        <td>Repasse a pagar pela clínica<strong>{{ Format::money($s['internal_pending']) }}</strong>{{ Format::money($s['native']) }} recebido direto</td>
    </tr></table>
    <h2>Recebimentos ({{ $d['receipts']['count'] }} · {{ Format::money($d['receipts']['total']) }})</h2>
    <table><thead><tr><th>Data</th><th>Descrição</th><th>Forma</th><th class="num">Valor</th></tr></thead><tbody>
        @foreach ($d['receipts']['lines'] as $l)<tr><td>{{ $l['date'] }}</td><td>{{ $l['description'] }} ({{ $l['payer'] }})</td><td>{{ $l['method'] }}</td><td class="num">{{ Format::money($l['amount']) }}</td></tr>@endforeach
    </tbody></table>
    <h2>Repasse</h2>
    <table><thead><tr><th>Data</th><th>Origem</th><th class="num">Base</th><th class="num">Médico</th></tr></thead><tbody>
        @foreach ($s['lines'] as $l)<tr><td>{{ $l['date'] }}</td><td>{{ $l['mode'] }}</td><td class="num">{{ Format::money($l['base']) }}</td><td class="num">{{ Format::money($l['amount']) }}</td></tr>@endforeach
    </tbody></table>
    <h2>Convênios</h2>
    <p>{{ $i['guides'] }} guia(s) · apresentado {{ Format::money($i['total']) }} · pago {{ Format::money($i['paid']) }} · glosado {{ Format::money($i['glosa']) }}</p>
    <div class="foot">Fechado em {{ $closing->closed_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }} por {{ $d['closed_by'] ?? '' }}.
        @if ($closing->responded_at) {{ $closing->statusLabel() }} em {{ $closing->responded_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}.@endif
        Integridade (SHA-256): {{ $closing->hash }}</div>
</body></html>
