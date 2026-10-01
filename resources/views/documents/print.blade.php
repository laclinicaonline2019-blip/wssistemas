@php
    $fmtClass = 'fmt-'.$format.($format === 'thermal' ? ' w'.$thermalWidth : '');
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>{{ $docs->count() === 1 ? $docs->first()->typeLabel().' '.$docs->first()->displayNumber() : 'Documentos' }}</title>
@if ($pdf)
<style>
{!! file_get_contents(public_path('assets/css/document.css')) !!}
@page { margin: {{ $format === 'a5' ? '9mm 10mm' : '14mm 16mm' }}; }
</style>
@else
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="{{ asset('assets/css/document.css') }}?v={{ filemtime(public_path('assets/css/document.css')) }}">
<script src="{{ asset('assets/js/app.js') }}" defer></script>
@endif
</head>
<body class="{{ $fmtClass }} {{ $pdf ? 'is-pdf' : '' }} {{ $preview ? 'is-preview' : '' }}" @if (! $pdf && ! $preview) data-autoprint @endif>
@unless ($pdf || $preview)
<div class="print-toolbar no-print">
    <button type="button" data-print>Imprimir</button>
    <span>{{ ['a4' => 'Papel A4', 'a5' => 'Papel A5', 'thermal' => "Bobina {$thermalWidth} mm"][$format] }} · escala 100% · margens "nenhuma" · desative "cabeçalhos e rodapés" do navegador.</span>
</div>
@endunless
@foreach ($docs as $doc)
    @if ($doc->type === 'special_prescription')
        @include('documents._doc', ['doc' => $doc, 'via' => '1ª via — retenção da farmácia'])
        @include('documents._doc', ['doc' => $doc, 'via' => '2ª via — orientação ao paciente'])
    @else
        @include('documents._doc', ['doc' => $doc, 'via' => null])
    @endif
@endforeach
</body>
</html>
