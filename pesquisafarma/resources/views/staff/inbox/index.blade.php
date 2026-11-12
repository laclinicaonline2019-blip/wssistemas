@extends('layouts.app')
@section('title', 'Conversas')
@section('content')
<div class="top"><div><h1>Conversas do WhatsApp</h1><div class="sub">Todas as conversas, visíveis para todo o time de televendas.</div></div>
<form class="toolbar"><input name="q" value="{{ request('q') }}" placeholder="Nome ou telefone"><button class="btn">Buscar</button></form></div>
<div class="card table-wrap"><table>
  <tr><th>Cliente</th><th>Última mensagem</th><th>Quando</th><th></th></tr>
  @forelse($threads as $m)
  <tr>
    <td><a href="{{ route('inbox.show', $m->customer_id) }}"><b>{{ $m->customer?->displayName() }}</b></a>
      @if(($unread[$m->customer_id] ?? 0) > 0)<span class="pill-dot">{{ $unread[$m->customer_id] }}</span>@endif
      <div class="small muted">{{ \App\Support\Text::formatPhone($m->phone) }} · {{ $m->customer?->neighborhood?->name }}@if($m->customer && ! $m->customer->isRegistered()) · <span class="badge b-pendente">cadastro: {{ $m->customer->bot_state }}</span>@endif</div></td>
    <td>{{ $m->direction === 'in' ? '' : '↪ ' }}{{ \Illuminate\Support\Str::limit($m->body ?: '['.$m->type.']', 90) }}</td>
    <td class="nowrap small">{{ $m->created_at->diffForHumans() }}</td>
    <td><a class="btn sm" href="{{ route('inbox.show', $m->customer_id) }}">Abrir</a></td>
  </tr>
  @empty <tr><td colspan="4" class="empty">Nenhuma conversa ainda.</td></tr>
  @endforelse
</table>{{ $threads->links() }}</div>
@endsection
