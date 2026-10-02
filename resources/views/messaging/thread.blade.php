@extends('layouts.app', ['title' => 'Conversa'])

@section('content')
<div class="page-head">
    <div><h1>{{ $thread->patient?->displayName() ?? $thread->contact_name ?? 'Contato não identificado' }}</h1>
        <p class="mono">+{{ $thread->phone }} @if ($thread->channel?->isMock())<span class="badge badge-warning">MOCK — nada é enviado de verdade</span>@endif</p></div>
    <div class="row">
        @if ($thread->patient)<a class="btn" href="{{ route('patients.show', $thread->patient) }}">Ficha do paciente</a>@endif
        <form method="post" action="{{ route('messaging.threads.close', $thread) }}">@csrf<button class="btn" type="submit">{{ $thread->status === 'open' ? 'Encerrar conversa' : 'Reabrir' }}</button></form>
        <a class="btn" href="{{ route('messaging.inbox') }}">Voltar</a>
    </div>
</div>
@unless ($thread->patient)<div class="alert alert-warning">Telefone não vinculado a um único paciente — confira antes de passar informações.</div>@endunless

<section class="card mb-2">
    <ul class="chat">
        @forelse ($messages as $msg)
            <li class="chat__msg {{ $msg->direction === 'in' ? 'is-in' : 'is-out' }}">
                <div class="chat__bubble">{{ $msg->body }}</div>
                <div class="small muted">{{ $msg->created_at->timezone('America/Sao_Paulo')->format('d/m H:i') }} ·
                    {{ $msg->direction === 'in' ? 'paciente' : ($msg->creator?->name ?? $msg->purposeLabel()) }}
                    @if ($msg->direction === 'out') · {{ $msg->statusLabel() }}@endif @if ($msg->error) · <span class="text-danger">{{ $msg->error }}</span>@endif</div>
            </li>
        @empty
            <li class="empty">Sem mensagens.</li>
        @endforelse
    </ul>
</section>

<div class="grid grid-2">
    <section class="card">
        <div class="card__head"><h2>Responder</h2>@if ($thread->windowOpen())<span class="badge badge-success">janela de 24 h aberta</span>@else<span class="badge">janela fechada</span>@endif</div>
        @if ($thread->windowOpen())
            <form method="post" action="{{ route('messaging.threads.reply', $thread) }}" class="card__body stack">@csrf
                <label class="sr-only" for="rp">Mensagem</label><textarea id="rp" name="text" class="input" rows="3" maxlength="4000" required></textarea>
                <p class="help">Não envie resultados de exames ou diagnósticos por aqui — use o portal do paciente.</p>
                <button class="btn btn-primary" type="submit">Enviar</button></form>
        @else
            <p class="card__body small muted">O WhatsApp só permite texto livre até 24 horas depois da última mensagem do paciente. Fora disso, só modelos aprovados (lembretes e avisos automáticos).</p>
        @endif
    </section>
    @if ($thread->channel?->isMock())
        <section class="card"><div class="card__head"><h2>Simular resposta do paciente (MOCK)</h2></div>
            <form method="post" action="{{ route('messaging.threads.simulate', $thread) }}" class="card__body stack">@csrf
                <label for="sm">Texto</label><input id="sm" name="text" class="input" placeholder="1, 2, 3 ou qualquer texto">
                <button class="btn" type="submit">Simular mensagem recebida</button></form></section>
    @endif
</div>
@endsection
