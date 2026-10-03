@extends('layouts.app', ['title' => 'Fechamento — '.$doctor->displayName()])

@section('content')
<div class="page-head">
    <div><h1>{{ $doctor->displayName() }} — {{ \Carbon\CarbonImmutable::parse($period.'-01')->locale('pt_BR')->translatedFormat('F/Y') }}</h1><p>Prévia: confira antes de fechar. Depois de fechado o demonstrativo não muda (correção = nova versão).</p></div>
    <div class="row"><a class="btn" href="{{ route('closings.index', ['period' => $period]) }}">Voltar</a></div>
</div>
@include('closings._statement', ['data' => $data])
<section class="card mt-2">
    <div class="card__head"><h2>Fechar o mês</h2></div>
    <form method="post" action="{{ route('closings.store', $doctor) }}" class="card__body stack" data-confirm="Fechar o mês deste médico? O demonstrativo fica imutável.">@csrf
        <input type="hidden" name="period" value="{{ $period }}">
        @if ($data['split']['internal_pending'] > 0)
            <label class="check"><input type="checkbox" name="settle" value="1" checked><span>Lançar o repasse de {{ \App\Core\Support\Format::money($data['split']['internal_pending']) }} em contas a pagar</span></label>
        @endif
        <button class="btn btn-primary" type="submit">Fechar mês</button>
    </form>
    @if ($history->isNotEmpty())
        <div class="card__body small">Versões anteriores: @foreach ($history as $h)<a href="{{ route('closings.show', $h) }}">v{{ $h->version }} ({{ $h->statusLabel() }})</a>@if (! $loop->last), @endif @endforeach</div>
    @endif
</section>
@endsection
