<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Senha {{ $ticket->code }}</title>
<link rel="stylesheet" href="{{ asset('assets/css/print.css') }}?v={{ filemtime(public_path('assets/css/print.css')) }}">
<script src="{{ asset('assets/js/app.js') }}?v={{ filemtime(public_path('assets/js/app.js')) }}" defer></script>
</head>
<body class="print-thermal print-thermal-{{ $width }}" data-autoprint>
<div class="print-toolbar no-print"><button type="button" data-print>Imprimir</button><span>Bobina {{ $width }} mm</span></div>
<div class="ticket">
    <div class="t-center t-bold">{{ $company->trade_name }}</div>
    <div class="t-center t-small">{{ $branch?->name }}</div>
    <div class="t-rule"></div>
    <div class="t-center t-small">{{ config('aivexa.queue_types.'.$ticket->type.'.label', 'SENHA') }}</div>
    <div class="t-center t-huge">{{ $ticket->code }}</div>
    @if ($ticket->is_priority)<div class="t-center t-bold t-small">ATENDIMENTO PRIORITÁRIO</div>@endif
    <div class="t-rule"></div>
    @if ($ticket->appointment)
        <div class="t-small">Consulta: {{ $ticket->appointment->starts_at->setTimezone($branch?->timezone ?? 'America/Sao_Paulo')->format('H:i') }}{{ $ticket->doctor ? ' · '.$ticket->doctor->displayName() : '' }}</div>
    @endif
    <div class="t-small">Chegada: {{ $ticket->arrived_at->setTimezone($branch?->timezone ?? 'America/Sao_Paulo')->format('d/m/Y H:i') }}</div>
    <div class="t-small">Senhas à frente: {{ $ahead }}</div>
    <div class="t-rule"></div>
    <div class="t-center t-small">Aguarde ser chamado no painel.</div>
</div>
</body>
</html>
