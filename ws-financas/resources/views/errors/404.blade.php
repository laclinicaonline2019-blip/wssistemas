@extends('layouts.guest')
@section('title', '404 — WS Finanças em Dia')
@section('content')
<div class="card auth"><h2>404</h2><p class="mt0">{{ $exception?->getMessage() && 404 === 403 ? $exception->getMessage() : 'Página não encontrada.' }}</p><a class="btn" href="/">Voltar ao início</a></div>
@endsection
