@php
    use App\Core\Support\Format;
    $r = $t->receivable; $p = $r->patient;
    $tz = $branch?->timezone ?: 'America/Sao_Paulo';
    $when = $t->occurred_at->timezone($tz);
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Recibo</title>
<link rel="stylesheet" href="{{ asset('assets/css/document.css') }}?v={{ filemtime(public_path('assets/css/document.css')) }}">
<script src="{{ asset('assets/js/app.js') }}" defer></script>
</head>
<body class="fmt-{{ $format }} {{ $format === 'thermal' ? 'w'.$thermalWidth : '' }} {{ $preview ? 'is-preview' : '' }}" @unless ($preview) data-autoprint @endunless>
@unless ($preview)<div class="print-toolbar no-print"><button type="button" data-print>Imprimir</button></div>@endunless
<div class="doc">
    @if ($t->reversal)<div class="stamp-cancelled">RECEBIMENTO ESTORNADO — sem validade</div>@endif
    <table class="hdr"><tr>
        <td><div class="clinic">{{ $company->trade_name }}</div>
            <div class="muted">{{ $branch?->fullAddress() }}</div>
            <div class="muted">{{ collect([$company->document ? 'CNPJ '.Format::cnpj($company->document) : null, Format::phone($branch?->phone ?? $company->phone)])->filter()->implode(' · ') }}</div></td>
        <td class="r"><div class="doctor-name">RECIBO</div><div class="muted">nº {{ strtoupper(substr($t->id, -10)) }}</div></td>
    </tr></table>

    <div class="text">Recebemos de <strong>{{ $p?->displayName() ?? 'cliente' }}</strong>{{ $p?->cpf ? ', CPF '.Format::cpf($p->cpf) : '' }},
        a importância de <strong>{{ Format::money($t->amount_cents) }}</strong> ({{ Format::moneyInWords($t->amount_cents) }}),
        referente a {{ $r->description }}.</div>

    <table class="kv">
        <tr><td class="k">Forma</td><td>{{ $t->methodLabel() }}{{ $t->card_installments > 1 ? ' em '.$t->card_installments.'x' : '' }}{{ $t->card_brand ? ' · '.$t->card_brand : '' }}{{ $t->authorization_code ? ' · '.$t->authorization_code : '' }}</td></tr>
        @if ($r->balanceCents() > 0 && ! $t->reversal)<tr><td class="k">Saldo</td><td>{{ Format::money($r->balanceCents()) }} a receber</td></tr>@endif
    </table>

    <div class="place-date">{{ $branch?->city ? $branch->city.', ' : '' }}{{ $when->translatedFormat('d \d\e F \d\e Y') }}, {{ $when->format('H:i') }}</div>
    <div class="sign"><div class="sign-line"></div><div>{{ $company->trade_name }}</div><div class="muted">Recebido por {{ $t->creator?->name }}</div></div>
    <div class="muted">Este recibo não substitui a nota fiscal de serviço.</div>
</div>
</body>
</html>
