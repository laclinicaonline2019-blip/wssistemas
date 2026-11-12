@extends('layouts.guest')
@section('title', 'Verificação · Pesquisa Farma')
@section('content')
<div class="card"><h2>Verificação em duas etapas</h2>
  <p class="small muted">Digite o código de 6 dígitos do seu aplicativo autenticador.</p>
  <form method="post" action="{{ route('2fa.verify') }}">@csrf
    <div class="f"><input name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required autofocus></div>
    <button class="btn p lg" style="width:100%;justify-content:center">Confirmar</button></form>
  <p class="small"><a href="{{ url('/login') }}">Voltar</a></p>
</div>
@endsection
