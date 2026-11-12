@extends('layouts.guest')
@section('title', '503 — WS Finanças em Dia')
@section('content')
<div class="card auth"><h2>503</h2><p class="mt0">{{ $exception?->getMessage() && 503 === 403 ? $exception->getMessage() : 'Estamos em manutenção. Volte em alguns minutos.' }}</p><a class="btn" href="/">Voltar ao início</a></div>
@endsection
