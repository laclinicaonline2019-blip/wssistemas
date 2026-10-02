@extends('layouts.app', ['title' => 'Conversas'])

@section('content')
<div class="page-head">
    <div><h1>Conversas do WhatsApp</h1><p>Respostas dos pacientes aos lembretes e mensagens livres. Respostas "1/2/3" e botões são tratados automaticamente.</p></div>
    <form method="get" class="row"><label class="sr-only" for="iq">Buscar</label><input id="iq" name="q" value="{{ $q }}" class="input w-auto" placeholder="Paciente ou telefone"><button class="btn" type="submit">Buscar</button></form>
</div>
<section class="card">
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Contato</th><th>Telefone</th><th>Última mensagem</th><th>Situação</th><th></th></tr></thead>
        <tbody>
        @forelse ($threads as $t)
            <tr>
                <td><a href="{{ route('messaging.threads.show', $t) }}"><strong>{{ $t->patient?->displayName() ?? $t->contact_name ?? 'Não identificado' }}</strong></a>
                    @if ($t->patient)<span class="small muted">#{{ $t->patient->record_number }}</span>@endif
                    @if ($t->channel?->isMock())<span class="badge badge-warning">MOCK</span>@endif</td>
                <td class="mono small">+{{ $t->phone }}</td>
                <td class="small">{{ $t->last_message_at?->timezone('America/Sao_Paulo')->format('d/m H:i') ?? '—' }}</td>
                <td>@if ($t->unread_count)<span class="badge badge-danger">{{ $t->unread_count }} nova(s)</span>@endif
                    @if ($t->windowOpen())<span class="badge badge-success">janela aberta</span>@endif
                    @if ($t->status === 'closed')<span class="badge">encerrada</span>@endif</td>
                <td class="actions"><a class="btn btn-sm" href="{{ route('messaging.threads.show', $t) }}">Abrir</a></td>
            </tr>
        @empty
            <tr><td colspan="5" class="empty">Nenhuma conversa.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    @include('partials.pagination', ['paginator' => $threads])
</section>
@endsection
