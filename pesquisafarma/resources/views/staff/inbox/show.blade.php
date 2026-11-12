@extends('layouts.app')
@section('title', $customer->displayName())
@section('content')
<div class="top">
  <div><h1>{{ $customer->displayName() }}</h1><div class="sub">{{ \App\Support\Text::formatPhone($customer->phone) }} · {{ $customer->fullAddress() ?: 'endereço não informado' }}</div></div>
  <div class="row auto"><a class="btn" href="{{ route('customers.show', $customer) }}">Ficha do cliente</a><a class="btn p" href="{{ route('quotes.create', ['cliente' => $customer->id]) }}">+ Cotação</a></div>
</div>
<div class="grid" style="grid-template-columns:2fr 1fr">
  <div class="card">
    <div class="chat">
      @foreach($messages as $m)
      <div class="msg {{ $m->direction }}">
        @if($m->media_path && str_starts_with((string)$m->mime, 'image'))<a href="{{ route('file', ['mensagem', $m->id]) }}" target="_blank"><img src="{{ route('file', ['mensagem', $m->id]) }}" alt="imagem"></a>
        @elseif($m->media_path)<a href="{{ route('file', ['mensagem', $m->id]) }}" target="_blank">📎 arquivo ({{ $m->mime }})</a><br>@endif<span class="b">{{ $m->body }}</span>
        <div class="t">{{ $m->created_at->format('d/m H:i') }}@if($m->user) · {{ $m->user->name }}@endif @if($m->status === 'falhou')<b style="color:var(--red)">· falhou</b>@endif</div>
        @if($m->direction === 'in')
        <div class="row auto" style="margin-top:4px">
          @if($m->quote_id)<a class="btn sm" href="{{ route('quotes.show', $m->quote_id) }}">Ver cotação</a>
          @else<form method="post" action="{{ route('inbox.to_quote', $m) }}">@csrf<button class="btn sm">→ Cotação</button></form>@endif
          @if($m->media_path)<form method="post" action="{{ route('inbox.to_receipt', $m) }}">@csrf<button class="btn sm">→ Nota fiscal</button></form>@endif
        </div>
        @endif
      </div>
      @endforeach
    </div>
    <form method="post" action="{{ route('inbox.send', $customer) }}" style="margin-top:10px">@csrf
      <div class="row"><textarea name="text" rows="2" placeholder="Escreva a mensagem..." required></textarea><button class="btn g auto">Enviar</button></div>
    </form>
  </div>
  <div>
    <div class="card"><h3>Cliente</h3>
      <div class="small">Bairro: <b>{{ $customer->neighborhood?->name ?? '—' }}</b></div>
      <div style="margin:8px 0">@include('partials.loyalty-card', ['customer' => $customer, 'card' => $card])</div>
      <form method="post" action="{{ route('inbox.card', $customer) }}">@csrf<button class="btn sm g">💳 Enviar saldo de pontos ao cliente</button></form>
      <div class="small">Promoções: {{ $customer->accepts_marketing ? 'aceita' : 'não aceita' }}</div>
      @unless($customer->isRegistered())<div class="alert err small" style="margin-top:8px">Cadastro incompleto (etapa: {{ $customer->bot_state }}). <a href="{{ route('customers.edit', $customer) }}">Completar</a></div>@endunless
    </div>
    <div class="card"><h3>Últimas cotações</h3>
      @forelse($quotes as $q)<div class="small"><a href="{{ route('quotes.show', $q) }}">{{ $q->code }}</a> <span class="badge b-{{ $q->status }}">{{ $q->statusLabel() }}</span></div>@empty<div class="muted small">Nenhuma.</div>@endforelse
    </div>
  </div>
</div>
@endsection
