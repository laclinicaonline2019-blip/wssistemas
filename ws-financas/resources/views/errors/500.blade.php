@extends('layouts.guest')
@section('title', '500 — WS Finanças em Dia')
@section('content')
<div class="card auth"><h2>500</h2><p class="mt0">{{ $exception?->getMessage() && 500 === 403 ? $exception->getMessage() : 'Algo deu errado do nosso lado. Já registramos o erro.' }}</p><a class="btn" href="/">Voltar ao início</a></div>
@endsection
