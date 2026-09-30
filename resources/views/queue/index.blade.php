@extends('layouts.app', ['title' => 'Fila de atendimento'])

@php
    $me = auth()->user();
    $manage = $me->hasPermission('fila.gerenciar', $branch->id);
    $waiting = $tickets->where('status', 'waiting');
@endphp

@section('content')
<div class="page-head" data-autorefresh="20">
    <div><h1>Fila — {{ $branch->name }}</h1><p>{{ now()->setTimezone($branch->timezone)->translatedFormat('l, d/m/Y') }} · {{ $waiting->count() }} aguardando · atualiza automaticamente</p></div>
    <div class="row">
        @if ($me->hasPermission('fila.painel'))<a class="btn" href="{{ route('queue.panel', ['branch_id' => $branch->id]) }}">Painel da TV</a>@endif
        <form method="get" class="row"><label class="sr-only" for="qb">Unidade</label>
            <select id="qb" name="branch_id" class="input w-auto" data-autosubmit>@foreach ($branches as $b)<option value="{{ $b->id }}" @selected($b->id === $branch->id)>{{ $b->name }}</option>@endforeach</select></form>
    </div>
</div>

@if (session('print_ticket'))
    <iframe class="print-frame" src="{{ route('queue.print', session('print_ticket')) }}" title="Impressão da senha"></iframe>
    <div class="alert alert-info">Imprimindo a senha… <a href="{{ route('queue.print', session('print_ticket')) }}" target="_blank" rel="noopener">abrir impressão</a></div>
@endif

@if ($manage)
<div class="grid grid-2">
    <section class="card">
        <div class="card__head"><h2>Chamar próxima senha</h2></div>
        <form method="post" action="{{ route('queue.call_next') }}" class="card__body row">
            @csrf <input type="hidden" name="branch_id" value="{{ $branch->id }}">
            <label class="sr-only" for="cn-room">Sala</label>
            <select id="cn-room" name="room_id" class="input w-auto"><option value="">Sala da senha / recepção</option>@foreach ($rooms as $r)<option value="{{ $r->id }}">{{ $r->label() }}</option>@endforeach</select>
            <button class="btn btn-brand" type="submit" @disabled($waiting->isEmpty())>Chamar próxima</button>
            <span class="small muted">Prioridades são chamadas primeiro.</span>
        </form>
    </section>
    <section class="card">
        <div class="card__head"><h2>Emitir senha (sem agendamento)</h2></div>
        <form method="post" action="{{ route('queue.issue') }}" class="card__body row">
            @csrf <input type="hidden" name="branch_id" value="{{ $branch->id }}">
            @foreach ($types as $k => $t)
                <button class="btn {{ $t['priority'] ? 'btn-danger' : '' }}" type="submit" name="type" value="{{ $k }}">{{ $t['prefix'] }} · {{ $t['label'] }}</button>
            @endforeach
        </form>
    </section>
</div>
@endif

<section class="card mt-2">
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Senha</th><th>Paciente</th><th class="hide-sm">Médico / sala</th><th>Situação</th><th class="hide-sm">Espera</th><th></th></tr></thead>
        <tbody>
        @forelse ($tickets as $t)
            <tr class="{{ $t->isOpen() ? '' : 'muted' }}">
                <td><span class="ticket-code {{ $t->is_priority ? 'ticket-code--priority' : '' }}">{{ $t->code }}</span>
                    @if ($t->call_count > 1)<div class="small muted">{{ $t->call_count }} chamadas</div>@endif</td>
                <td>{{ $t->patient?->displayName() ?? '—' }}
                    @if ($t->appointment)<div class="small muted">agendado {{ $t->appointment->starts_at->setTimezone($branch->timezone)->format('H:i') }}</div>@endif</td>
                <td class="hide-sm small">{{ $t->doctor?->displayName() ?? '—' }}{{ $t->room ? ' · '.$t->room->label() : '' }}</td>
                <td><span class="badge {{ ['waiting' => 'badge-info', 'called' => 'badge-warning', 'in_service' => 'badge-primary', 'done' => 'badge-success', 'skipped' => 'badge-danger'][$t->status] ?? '' }}">{{ $t->statusLabel() }}</span></td>
                <td class="hide-sm small">{{ $t->waitMinutes() }} min</td>
                <td class="actions">
                    @if ($manage && $t->isOpen())
                        <div class="row">
                            @if ($t->status === 'waiting')
                                <form method="post" action="{{ route('queue.action', [$t, 'call']) }}" class="row">@csrf
                                    <label class="sr-only" for="room-{{ $t->id }}">Sala</label>
                                    <select id="room-{{ $t->id }}" name="room_id" class="input input-sm"><option value="">{{ $t->room?->label() ?? 'sala' }}</option>@foreach ($rooms as $r)<option value="{{ $r->id }}">{{ $r->label() }}</option>@endforeach</select>
                                    <button class="btn btn-sm btn-primary" type="submit">Chamar</button></form>
                            @endif
                            @if ($t->status === 'called')
                                <form method="post" action="{{ route('queue.action', [$t, 'recall']) }}">@csrf<button class="btn btn-sm" type="submit">Rechamar</button></form>
                                <form method="post" action="{{ route('queue.action', [$t, 'start']) }}">@csrf<button class="btn btn-sm btn-primary" type="submit">Iniciar</button></form>
                            @endif
                            @if ($t->status === 'in_service')
                                <form method="post" action="{{ route('queue.action', [$t, 'finish']) }}">@csrf<button class="btn btn-sm btn-primary" type="submit">Finalizar</button></form>
                                <form method="post" action="{{ route('queue.action', [$t, 'transfer']) }}" class="row">@csrf
                                    <label class="sr-only" for="tr-{{ $t->id }}">Encaminhar para</label>
                                    <select id="tr-{{ $t->id }}" name="room_id" class="input input-sm">@foreach ($rooms as $r)<option value="{{ $r->id }}">{{ $r->label() }}</option>@endforeach</select>
                                    <button class="btn btn-sm" type="submit">Encaminhar</button></form>
                            @endif
                            @if (in_array($t->status, ['waiting', 'called'], true))
                                <form method="post" action="{{ route('queue.action', [$t, 'skip']) }}" data-confirm="Marcar {{ $t->code }} como não compareceu?">@csrf<button class="btn btn-sm btn-ghost" type="submit">Pular</button></form>
                            @endif
                            <a class="btn btn-sm btn-ghost" href="{{ route('queue.print', $t) }}" target="_blank" rel="noopener" aria-label="Imprimir senha {{ $t->code }}"><svg><use href="#i-printer"/></svg></a>
                        </div>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty">Nenhuma senha emitida hoje.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>
@endsection
