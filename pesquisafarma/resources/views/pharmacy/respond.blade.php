@extends($layout)
@section('title', 'Cotação '.$dispatch->quote->code)
@section('box', 'wide')
@section('content')
@php($q = $dispatch->quote)
<div class="card">
  <div class="top" style="margin:0">
    <div><h1>Cotação {{ $q->code }}</h1>
      <div class="sub">{{ $dispatch->pharmacy->trade_name }} · cliente no bairro <b>{{ $q->neighborhood?->name ?? 'não informado' }}</b>
        @if($dispatch->distance_km !== null) (≈ {{ number_format($dispatch->distance_km, 1, ',', '.') }} km)@endif
        · {{ $q->fulfillment === 'entrega' ? '🛵 quer entrega' : '🏪 vai retirar' }}</div></div>
    <div><span class="badge b-{{ $dispatch->status }}">{{ $dispatch->statusLabel() }}</span>
      @if($q->response_deadline_at && $dispatch->status !== 'respondida')<div class="small muted">Responder até {{ $q->response_deadline_at->format('H:i') }}</div>@endif</div>
  </div>
</div>
@if(! $q->isOpen())
  <div class="alert err">Esta cotação já foi encerrada ({{ $q->statusLabel() }}).</div>
@else
  <div class="card">@include('pharmacy._form', ['dispatch' => $dispatch, 'action' => $action, 'declineAction' => $declineAction])</div>
@endif
@endsection
