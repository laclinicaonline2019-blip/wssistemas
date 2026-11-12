@extends('layouts.guest')
@section('title', 'Códigos de recuperação')
@section('content')
<div class="card auth">
  <h2>Verificação ativada ✓</h2>
  <p class="mt0">Guarde estes <b>códigos de recuperação</b> em lugar seguro. Cada um funciona uma única vez, caso você perca o celular. Eles não serão mostrados de novo.</p>
  <div class="codes">@foreach ($codes as $c)<span>{{ $c }}</span>@endforeach</div>
  <a class="btn primary" href="{{ auth()->user()->is_platform_admin ? route('admin.dashboard') : route('app') }}">Continuar</a>
</div>
@endsection
