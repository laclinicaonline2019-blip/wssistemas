<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Teste térmica {{ $width }} mm</title>
<link rel="stylesheet" href="{{ asset('assets/css/print.css') }}?v={{ filemtime(public_path('assets/css/print.css')) }}">
<script src="{{ asset('assets/js/app.js') }}" defer></script>
</head>
<body class="print-thermal print-thermal-{{ $width }}" data-autoprint>
<div class="print-toolbar no-print">
    <button type="button" data-print>Imprimir</button>
    <span>Bobina {{ $width }} mm · escala 100% · margens "nenhuma".</span>
</div>
<div class="ticket">
    <div class="t-center t-bold">{{ $company->trade_name }}</div>
    <div class="t-center t-small">{{ $branch?->name }}</div>
    <div class="t-rule"></div>
    <div class="t-center t-small">SENHA DE ATENDIMENTO</div>
    <div class="t-center t-huge">A000</div>
    <div class="t-center t-small">TESTE DE IMPRESSORA</div>
    <div class="t-rule"></div>
    <div class="t-small">Data: {{ now()->timezone($branch?->timezone ?? 'America/Sao_Paulo')->format('d/m/Y H:i') }}</div>
    <div class="t-small">Largura configurada: {{ $width }} mm</div>
    <div class="t-rule"></div>
    <div class="t-center t-small">Aguarde ser chamado no painel.</div>
</div>
</body>
</html>
