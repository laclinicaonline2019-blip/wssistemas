@extends('layouts.app', ['title' => 'Agendamento '.$appointment->protocol])

@php
    use App\Core\Support\Format;
    $me = auth()->user();
    $tz = $appointment->branch->timezone;
    $local = $appointment->starts_at->setTimezone($tz);
    $bid = $appointment->branch_id;
@endphp

@section('content')
<div class="page-head">
    <div><h1>{{ $appointment->patient->displayName() }}</h1>
        <p>Protocolo <span class="record-no">{{ $appointment->protocol }}</span> · @include('agenda._status', ['status' => $appointment->status])
            @if ($appointment->is_overbook)<span class="badge badge-warning">encaixe</span>@endif</p></div>
    <div class="row">
        @if ($appointment->ticket)<a class="btn" href="{{ route('queue.print', $appointment->ticket) }}" target="_blank" rel="noopener"><svg><use href="#i-printer"/></svg>Senha {{ $appointment->ticket->code }}</a>@endif
        <a class="btn" href="{{ route('agenda.index', ['date' => $local->toDateString(), 'branch_id' => $bid]) }}">Agenda do dia</a>
    </div>
</div>

@if (session('print_ticket'))
    <div class="alert alert-info">Senha emitida. <a href="{{ route('queue.print', session('print_ticket')) }}" target="_blank" rel="noopener" data-autoopen>Imprimir senha</a></div>
@endif

<div class="grid grid-2">
    <section class="card">
        <div class="card__head"><h2>Dados do agendamento</h2></div>
        <div class="card__body">
            <dl class="dl">
                <dt>Data e hora</dt><dd><strong>{{ $local->translatedFormat('l, d/m/Y') }} às {{ $local->format('H:i') }}</strong> ({{ $appointment->starts_at->diffInMinutes($appointment->ends_at) }} min)</dd>
                <dt>Médico</dt><dd>{{ $appointment->doctor->displayName() }} · {{ $appointment->doctor->registration() }}</dd>
                <dt>Unidade</dt><dd>{{ $appointment->branch->name }}{{ $appointment->room ? ' · '.$appointment->room->label() : '' }}</dd>
                <dt>Atendimento</dt><dd>{{ $appointment->service?->name ?? 'Consulta' }}</dd>
                <dt>Pagamento</dt><dd>{{ $appointment->payer_type === 'insurance' ? 'Convênio: '.($appointment->insurance?->insurer_name ?? '—') : 'Particular · '.$appointment->priceFormatted() }}
                    @if ($receivable)
                        <span class="badge {{ ['paid' => 'badge-success', 'cancelled' => ''][$receivable->status] ?? 'badge-warning' }}">{{ $receivable->statusLabel() }}</span>
                        @if (auth()->user()->hasPermission('caixa.operar') || auth()->user()->hasPermission('financeiro.visualizar'))
                            <a class="btn btn-sm {{ in_array($receivable->status, ['open', 'partial'], true) ? 'btn-primary' : '' }}" href="{{ route('receivables.show', $receivable) }}">{{ in_array($receivable->status, ['open', 'partial'], true) ? 'Receber' : 'Ver cobrança' }}</a>
                        @endif
                    @elseif ($appointment->payer_type === 'private' && $appointment->price_cents > 0)
                        <span class="small muted">· a cobrança é gerada na chegada</span>
                    @endif
                    @if ($appointment->payer_type === 'insurance')
                        <span class="small muted mono">· carteirinha {{ $appointment->insurance?->card_number }}</span>
                        @if ($guide)
                            @include('insurance._guide-badge', ['g' => $guide])
                            @if (auth()->user()->hasPermission('convenio.faturar'))<a class="btn btn-sm" href="{{ route('guides.show', $guide) }}">Guia {{ $guide->number }}</a>@endif
                        @elseif ($appointment->arrived_at && auth()->user()->hasPermission('convenio.faturar'))
                            <form method="post" action="{{ route('guides.from_appointment', $appointment) }}" class="inline">@csrf<button class="btn btn-sm btn-primary" type="submit">Gerar guia do convênio</button></form>
                        @else
                            <span class="small muted">· a guia do convênio é gerada na chegada</span>
                        @endif
                    @endif</dd>
                <dt>Paciente</dt><dd><a href="{{ route('patients.show', $appointment->patient) }}">#{{ $appointment->patient->record_number }} {{ $appointment->patient->displayName() }}</a>
                    <div class="small muted">{{ Format::phone($appointment->patient->whatsapp ?? $appointment->patient->phone) }}</div></dd>
                <dt>Canal</dt><dd>{{ \App\Modules\Scheduling\Models\Appointment::CHANNELS[$appointment->channel] ?? $appointment->channel }} · por {{ $appointment->creator?->name ?? 'sistema' }}</dd>
                @if ($appointment->notes)<dt>Observações</dt><dd>{{ $appointment->notes }}</dd>@endif
                @if ($appointment->cancel_reason)<dt>Motivo do cancelamento</dt><dd>{{ $appointment->cancel_reason }}</dd>@endif
            </dl>
        </div>
    </section>

    <section class="card">
        <div class="card__head"><h2>Ações</h2></div>
        <div class="card__body stack">
            @if ($appointment->canTransitionTo('arrived') && $me->hasPermission('fila.gerenciar', $bid))
                <form method="post" action="{{ route('agenda.action', [$appointment, 'arrive']) }}" class="row">
                    @csrf
                    <label class="sr-only" for="ticket_type">Tipo de senha</label>
                    <select id="ticket_type" name="ticket_type" class="input w-auto">
                        @foreach ($ticketTypes as $k => $t)<option value="{{ $k }}" @selected($k === $suggestedType)>{{ $t['prefix'] }} — {{ $t['label'] }}</option>@endforeach
                    </select>
                    <button class="btn btn-primary" type="submit">Registrar chegada e gerar senha</button>
                </form>
            @endif
            @if ($appointment->canTransitionTo('confirmed') && $me->hasPermission('agenda.editar', $bid))
                <form method="post" action="{{ route('agenda.action', [$appointment, 'confirm']) }}">@csrf<button class="btn" type="submit">Confirmar presença</button></form>
            @endif
            @if (in_array($appointment->status, ['scheduled', 'confirmed'], true) && $me->hasPermission('agenda.editar', $bid))
                <form method="post" action="{{ route('agenda.action', [$appointment, 'reschedule']) }}" class="row">
                    @csrf
                    <label class="sr-only" for="r-date">Nova data</label><input id="r-date" type="date" name="date" class="input w-auto" value="{{ $local->toDateString() }}" required>
                    <label class="sr-only" for="r-time">Novo horário</label><input id="r-time" type="time" name="time" class="input w-auto" value="{{ $local->format('H:i') }}" required>
                    <button class="btn" type="submit">Remarcar</button>
                </form>
            @endif
            @if ($appointment->canTransitionTo('no_show') && $appointment->starts_at->isPast() && $me->hasPermission('agenda.editar', $bid))
                <form method="post" action="{{ route('agenda.action', [$appointment, 'no-show']) }}" data-confirm="Registrar falta do paciente?">@csrf<button class="btn" type="submit">Registrar falta</button></form>
            @endif
            @if ($appointment->canTransitionTo('cancelled') && $me->hasPermission('agenda.cancelar', $bid))
                <form method="post" action="{{ route('agenda.action', [$appointment, 'cancel']) }}" class="row" data-confirm="Cancelar este agendamento? O horário será liberado.">
                    @csrf
                    <label class="sr-only" for="reason">Motivo</label>
                    <input id="reason" name="reason" class="input grow @error('reason') is-invalid @enderror" placeholder="Motivo do cancelamento" required minlength="3" maxlength="255">
                    <button class="btn btn-danger" type="submit">Cancelar</button>
                </form>
            @endif
            @if (! $appointment->isActive() || $appointment->status === 'completed')
                <p class="muted small">Agendamento encerrado ({{ $appointment->statusLabel() }}).</p>
            @endif
        </div>
    </section>
</div>

<section class="card mt-2">
    <div class="card__head"><h2>Histórico</h2></div>
    <div class="card__body">
        <ul class="timeline">
            @foreach ($history as $log)
                <li class="spread"><span>{{ ['appointment.created' => 'Agendado', 'appointment.confirmed' => 'Presença confirmada', 'appointment.arrived' => 'Chegada registrada', 'appointment.in_service' => 'Atendimento iniciado', 'appointment.completed' => 'Atendimento finalizado', 'appointment.cancelled' => 'Cancelado', 'appointment.no_show' => 'Falta registrada', 'appointment.rescheduled' => 'Remarcado'][$log->action] ?? $log->action }}
                    @if ($log->action === 'appointment.cancelled' && ! empty($log->metadata['reason']))<span class="small muted">— {{ $log->metadata['reason'] }}</span>@endif</span>
                    <span class="small muted">{{ $log->created_at->setTimezone($tz)->format('d/m/Y H:i') }} · {{ $log->user?->name ?? 'Sistema' }}</span></li>
            @endforeach
        </ul>
    </div>
</section>
@endsection
