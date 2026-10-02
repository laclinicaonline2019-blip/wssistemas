@php
    use App\Core\Support\Format;
    use App\Modules\Insurance\Models\Guide;
    $g = $guide;
    $cnpj = $g->branch->document ?: $company->document;
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Guia {{ $g->number }}</title>
<link rel="stylesheet" href="{{ asset('assets/css/document.css') }}?v={{ filemtime(public_path('assets/css/document.css')) }}">
<script src="{{ asset('assets/js/app.js') }}" defer></script>
</head>
<body class="fmt-a4">
<div class="print-toolbar no-print"><button type="button" data-print>Imprimir</button></div>
<div class="doc">
    @if ($g->status === 'cancelled')<div class="stamp-cancelled">GUIA CANCELADA — sem validade</div>@endif
    <table class="hdr"><tr>
        <td><div class="clinic">{{ $company->trade_name }}</div>
            <div class="muted">{{ $g->branch->fullAddress() }}</div>
            <div class="muted">{{ collect([$cnpj ? 'CNPJ '.Format::cnpj($cnpj) : null, $g->branch->cnes ? 'CNES '.$g->branch->cnes : null])->filter()->implode(' · ') }}</div></td>
        <td class="r"><div class="doctor-name">{{ $g->guide_type === 'consulta' ? 'GUIA DE CONSULTA' : 'GUIA DE SP/SADT' }}</div>
            <div class="muted">Padrão TISS · nº prestador {{ $g->number }}</div>
            @if ($g->operator_guide_number)<div class="muted">nº operadora {{ $g->operator_guide_number }}</div>@endif</td>
    </tr></table>

    <table class="kv guide-kv">
        <tr><td class="k">Operadora</td><td>{{ $g->insurer->name }} · Registro ANS {{ $g->insurer->ans_registry ?? '—' }}{{ $g->plan ? ' · plano '.$g->plan->name : '' }}</td></tr>
        <tr><td class="k">Beneficiário</td><td>{{ $g->patient->displayName() }}</td></tr>
        <tr><td class="k">Carteira</td><td>{{ $g->card_number }}{{ $g->card_valid_until ? ' · validade '.$g->card_valid_until->format('d/m/Y') : '' }}</td></tr>
        @if ($g->authorization)<tr><td class="k">Autorização</td><td>Senha {{ $g->authorization->password ?? '—' }}{{ $g->authorization->valid_until ? ' · validade '.$g->authorization->valid_until->format('d/m/Y') : '' }}</td></tr>@endif
        <tr><td class="k">Profissional</td><td>{{ $g->doctor->name }} · CRM {{ $g->doctor->crm }}/{{ $g->doctor->crm_state }} · CBO {{ $g->cbo_code }}</td></tr>
        <tr><td class="k">Atendimento</td><td>{{ $g->attendance_date->format('d/m/Y') }} · {{ Guide::CONSULTATION_TYPES[$g->consultation_type] ?? '' }} · {{ Guide::ACCIDENT[$g->accident_indicator] ?? '' }}{{ $g->character === '2' ? ' · urgência' : '' }}</td></tr>
        @if ($g->clinical_indication)<tr><td class="k">Indicação clínica</td><td>{{ $g->clinical_indication }}</td></tr>@endif
    </table>

    <table class="grid-items">
        <thead><tr><th>Data</th><th>Tabela</th><th>Código</th><th>Descrição</th><th class="r">Qtde</th><th class="r">Valor unit.</th><th class="r">Total</th></tr></thead>
        <tbody>
        @foreach ($g->items as $it)
            <tr><td>{{ $it->execution_date->format('d/m/Y') }}</td><td>{{ $it->table_code }}</td><td>{{ $it->code }}</td><td>{{ $it->description }}</td>
                <td class="r">{{ $it->quantity }}</td><td class="r">{{ Format::money($it->unit_cents) }}</td><td class="r">{{ Format::money($it->total_cents) }}</td></tr>
        @endforeach
        <tr><td colspan="6" class="r"><strong>Total</strong></td><td class="r"><strong>{{ Format::money($g->total_cents) }}</strong></td></tr>
        </tbody>
    </table>
    @if ($g->observation)<div class="text">Observação: {{ $g->observation }}</div>@endif

    <div class="sign-row">
        <div class="sign"><div class="sign-line"></div><div>Assinatura do beneficiário ou responsável</div><div class="muted">Data: ____/____/______</div></div>
        <div class="sign"><div class="sign-line"></div><div>{{ $g->doctor->name }}</div><div class="muted">CRM {{ $g->doctor->crm }}/{{ $g->doctor->crm_state }}</div></div>
    </div>
    <div class="muted">Espelho da guia para conferência e assinatura. O faturamento eletrônico segue no lote XML TISS {{ $g->insurer->tiss_version }}.</div>
</div>
</body>
</html>
