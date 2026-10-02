@extends('layouts.app', ['title' => 'Notificações'])

@section('content')
<div class="page-head">
    <div><h1>Notificações</h1><p>Avisos para a equipe: respostas de pacientes, cancelamentos e remarcações pedidos pelo WhatsApp, mensagens não entregues.</p></div>
    <form method="post" action="{{ route('notifications.read_all') }}">@csrf<button class="btn" type="submit">Marcar todas como lidas</button></form>
</div>
<section class="card">
    <ul class="portal-list">
        @forelse ($items as $n)
            <li class="{{ in_array($n->id, $read, true) ? 'muted' : '' }}">
                <span><span class="badge {{ ['warning' => 'badge-warning', 'danger' => 'badge-danger'][$n->level] ?? 'badge-info' }}">{{ in_array($n->id, $read, true) ? 'lida' : 'nova' }}</span>
                    <strong>{{ $n->title }}</strong><br><span class="small">{{ $n->body }}</span><br>
                    <span class="small muted">{{ $n->created_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</span></span>
                <a class="btn btn-sm" href="{{ route('notifications.open', $n) }}">Abrir</a>
            </li>
        @empty
            <li class="muted">Nenhuma notificação.</li>
        @endforelse
    </ul>
    @include('partials.pagination', ['paginator' => $items])
</section>
@endsection
