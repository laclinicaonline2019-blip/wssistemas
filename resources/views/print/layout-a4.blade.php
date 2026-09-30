<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>{{ $title ?? 'Impressão' }}</title>
<link rel="stylesheet" href="{{ asset('assets/css/print.css') }}?v={{ filemtime(public_path('assets/css/print.css')) }}">
<script src="{{ asset('assets/js/app.js') }}" defer></script>
</head>
<body class="print-a4" @if ($autoprint ?? false) data-autoprint @endif>
<div class="print-toolbar no-print">
    <button type="button" data-print>Imprimir</button>
    <span>Papel A4 · margens 15 mm · desative "cabeçalhos e rodapés" do navegador.</span>
</div>
<div class="sheet">
    <header class="doc-header">
        <div>
            <div class="doc-company">{{ $company->trade_name }}</div>
            <div class="doc-muted">{{ $branch?->fullAddress() }}</div>
            <div class="doc-muted">{{ collect([$branch?->phone ?? $company->phone, $company->email])->filter()->implode(' · ') }}</div>
        </div>
        @if ($company->setting('print.header_text'))<div class="doc-muted doc-right">{{ $company->setting('print.header_text') }}</div>@endif
    </header>

    <main class="doc-body">@yield('body')</main>

    <footer class="doc-footer">
        <span>{{ $company->setting('print.footer_text') }}</span>
        <span>Emitido em {{ now()->timezone($branch?->timezone ?? 'America/Sao_Paulo')->format('d/m/Y H:i') }}</span>
    </footer>
</div>
</body>
</html>
