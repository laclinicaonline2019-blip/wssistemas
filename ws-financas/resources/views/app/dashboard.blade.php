@extends('layouts.app')
@section('title', 'WS Finanças em Dia')
@section('wrapattrs') id="app" data-boot="{{ json_encode($boot) }}" @endsection
@section('tools')
  @if ($companies->count() > 1 || $boot['consolidado'])
    <select id="empresa" aria-label="Empresa">
      @foreach ($companies as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
      @if ($boot['consolidado'])<option value="todas">Todas as empresas (consolidado)</option>@endif
    </select>
  @endif
  <div class="monthnav" aria-label="Mês">
    <button type="button" id="prev" aria-label="Mês anterior">‹</button>
    <span id="mesLabel" class="num">…</span>
    <button type="button" id="next" aria-label="Próximo mês">›</button>
  </div>
@endsection
@section('content')
  <nav class="tabs" role="tablist">
    <button type="button" role="tab" data-tab="mes" aria-selected="true">Contas do mês</button>
    <button type="button" role="tab" data-tab="dividas" aria-selected="false">Plano das dívidas</button>
    <button type="button" role="tab" data-tab="fechamento" aria-selected="false">Fechamento e economia</button>
    <button type="button" role="tab" data-tab="fluxo" aria-selected="false">Fluxo de caixa</button>
    <button type="button" role="tab" data-tab="relatorios" aria-selected="false">Relatórios</button>
    <button type="button" role="tab" data-tab="whats" aria-selected="false">Lançar por mensagem</button>
  </nav>
  <div id="notice"></div>
  <main id="view"><div class="empty">Abrindo suas contas…</div></main>
@endsection
@push('scripts')
<script src="{{ asset('assets/js/app.js') }}?v={{ filemtime(public_path('assets/js/app.js')) }}"></script>
@endpush
