@extends('layouts.guest')
@section('title', '429 — WS Finanças em Dia')
@section('content')
<div class="card auth"><h2>429</h2><p class="mt0">{{ $exception?->getMessage() && 429 === 403 ? $exception->getMessage() : 'Muitas tentativas. Aguarde um pouco e tente novamente.' }}</p><a class="btn" href="/">Voltar ao início</a></div>
@endsection
