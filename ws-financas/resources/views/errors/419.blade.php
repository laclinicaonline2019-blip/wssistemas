@extends('layouts.guest')
@section('title', '419 — WS Finanças em Dia')
@section('content')
<div class="card auth"><h2>419</h2><p class="mt0">{{ $exception?->getMessage() && 419 === 403 ? $exception->getMessage() : 'A sessão expirou. Recarregue a página e tente de novo.' }}</p><a class="btn" href="/">Voltar ao início</a></div>
@endsection
