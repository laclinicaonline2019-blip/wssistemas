@extends('layouts.guest')
@section('title', '403 — WS Finanças em Dia')
@section('content')
<div class="card auth"><h2>403</h2><p class="mt0">{{ $exception?->getMessage() && 403 === 403 ? $exception->getMessage() : 'Você não tem permissão para acessar esta página.' }}</p><a class="btn" href="/">Voltar ao início</a></div>
@endsection
