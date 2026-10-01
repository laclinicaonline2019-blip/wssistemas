@php use App\Core\Support\Format; $tz = $session->branch->timezone ?: 'America/Sao_Paulo'; $w = (int) $company->setting('print.thermal_width_mm', 80); @endphp
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Fechamento de caixa</title>
<link rel="stylesheet" href="{{ asset('assets/css/document.css') }}?v={{ filemtime(public_path('assets/css/document.css')) }}">
<script src="{{ asset('assets/js/app.js') }}" defer></script>
</head>
<body class="fmt-{{ $format }} {{ $format === 'thermal' ? 'w'.$w : '' }}" data-autoprint>
<div class="print-toolbar no-print"><button type="button" data-print>Imprimir</button></div>
<div class="doc">
    <table class="hdr"><tr><td><div class="clinic">{{ $company->trade_name }}</div><div class="muted">{{ $session->branch->name }}</div></td>
        <td class="r"><div class="doctor-name">FECHAMENTO DE CAIXA</div><div class="muted">Operador: {{ $session->operator->name }}</div></td></tr></table>
    <table class="kv">
        <tr><td class="k">Abertura</td><td>{{ $session->opened_at->timezone($tz)->format('d/m/Y H:i') }}</td></tr>
        <tr><td class="k">Fechamento</td><td>{{ $session->closed_at?->timezone($tz)->format('d/m/Y H:i') ?? 'em aberto' }}</td></tr>
        <tr><td class="k">Troco inicial</td><td>{{ Format::money($session->opening_cents) }}</td></tr>
    </table>
    <div class="route">Movimentações</div>
    <table class="items">
        @foreach ($transactions as $t)
            <tr><td>{{ $t->occurred_at->timezone($tz)->format('H:i') }} {{ \App\Modules\Finance\Models\FinancialTransaction::KINDS[$t->kind] }} · {{ $t->methodLabel() }}</td><td class="q">{{ Format::money($t->signedCents()) }}</td></tr>
        @endforeach
    </table>
    @if ($session->expected)
        <div class="route">Conferência</div>
        <table class="items">
            <tr><td><strong>Forma</strong></td><td class="q">Esperado / Declarado</td></tr>
            @foreach ($session->expected as $m => $exp)
                <tr><td>{{ $methods[$m] ?? $m }}</td><td class="q">{{ Format::money($exp) }} / {{ Format::money($session->declared[$m] ?? 0) }}</td></tr>
            @endforeach
            <tr><td><strong>Diferença</strong></td><td class="q">{{ Format::money($session->difference_cents) }}</td></tr>
        </table>
    @else
        <div class="notes">Dinheiro esperado na gaveta: <strong>{{ Format::money($summary['cash_expected']) }}</strong></div>
    @endif
    <div class="sign"><div class="sign-line"></div><div>{{ $session->operator->name }} — operador</div></div>
    <div class="sign"><div class="sign-line"></div><div>{{ $session->reviewer?->name ?? 'Conferente' }}</div></div>
    <div class="muted">Impresso em {{ now()->timezone($tz)->format('d/m/Y H:i') }}</div>
</div>
</body>
</html>
