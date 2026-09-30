@extends('layouts.app', ['title' => 'Agenda'])

@php
    $me = auth()->user();
    $tz = $branch->timezone;
    $canBook = $me->hasPermission('agenda.criar', $branch->id);
    $canOverbook = $me->hasPermission('agenda.encaixe', $branch->id);
@endphp

@section('content')
<div class="page-head">
    <div><h1>Agenda — {{ $date->translatedFormat('l, d \d\e F') }}</h1><p>{{ $branch->name }}</p></div>
    <div class="row">
        <a class="btn" href="{{ route('agenda.index', ['date' => $date->subDay()->toDateString(), 'branch_id' => $branch->id, 'doctor_id' => request('doctor_id')]) }}" aria-label="Dia anterior">‹</a>
        <a class="btn" href="{{ route('agenda.index', ['branch_id' => $branch->id, 'doctor_id' => request('doctor_id')]) }}">Hoje</a>
        <a class="btn" href="{{ route('agenda.index', ['date' => $date->addDay()->toDateString(), 'branch_id' => $branch->id, 'doctor_id' => request('doctor_id')]) }}" aria-label="Próximo dia">›</a>
    </div>
</div>

<section class="card">
    <div class="card__body">
        <form class="toolbar" method="get">
            <div class="field"><label for="date">Data</label><input id="date" type="date" name="date" class="input" value="{{ $date->toDateString() }}"></div>
            <div class="field"><label for="branch_id">Unidade</label>
                <select id="branch_id" name="branch_id" class="input">@foreach ($branches as $b)<option value="{{ $b->id }}" @selected($b->id === $branch->id)>{{ $b->name }}</option>@endforeach</select></div>
            <div class="field"><label for="doctor_id">Médico</label>
                <select id="doctor_id" name="doctor_id" class="input"><option value="">Todos</option>
                    @foreach ($allDoctors as $d)<option value="{{ $d->id }}" @selected(request('doctor_id') === $d->id)>{{ $d->displayName() }}</option>@endforeach</select></div>
            <button class="btn" type="submit">Ver agenda</button>
        </form>
    </div>
</section>

<section class="card">
    <div class="card__head"><h2>Próximos horários disponíveis</h2></div>
    <div class="card__body">
        <form class="toolbar" method="get">
            <input type="hidden" name="date" value="{{ $date->toDateString() }}"><input type="hidden" name="branch_id" value="{{ $branch->id }}">
            <div class="field"><label for="specialty_id">Especialidade</label>
                <select id="specialty_id" name="specialty_id" class="input"><option value="">—</option>
                    @foreach ($specialties as $s)<option value="{{ $s->id }}" @selected(request('specialty_id') === $s->id)>{{ $s->name }}</option>@endforeach</select></div>
            <div class="field"><label for="next_doctor_id">ou médico</label>
                <select id="next_doctor_id" name="next_doctor_id" class="input"><option value="">—</option>
                    @foreach ($allDoctors as $d)<option value="{{ $d->id }}" @selected(request('next_doctor_id') === $d->id)>{{ $d->displayName() }}</option>@endforeach</select></div>
            <button class="btn" type="submit">Buscar horários</button>
        </form>
        @if ($next !== null)
            @if ($next === [])
                <p class="muted mt-1">Nenhum horário livre nos próximos 60 dias para este filtro.</p>
            @else
                <div class="chips mt-1">
                    @foreach ($next as $slot)
                        @if ($canBook)
                            <a class="btn btn-sm" href="{{ route('agenda.create', ['doctor_id' => $slot->doctorId, 'branch_id' => $branch->id, 'starts_at' => $slot->start->toIso8601String()]) }}">
                                {{ $slot->start->setTimezone($tz)->format('d/m H:i') }} · {{ $doctorNames[$slot->doctorId] ?? '' }}</a>
                        @else
                            <span class="badge">{{ $slot->start->setTimezone($tz)->format('d/m H:i') }} · {{ $doctorNames[$slot->doctorId] ?? '' }}</span>
                        @endif
                    @endforeach
                </div>
            @endif
        @endif
    </div>
</section>

@forelse ($boards as $board)
    @php $doctor = $board['doctor']; $appts = $board['appointments']; @endphp
    <section class="card agenda-board">
        <div class="card__head">
            <div><h2>{{ $doctor->displayName() }}</h2><div class="small muted">{{ $doctor->registration() }} · {{ $doctor->specialties->pluck('name')->implode(', ') }}</div></div>
            @if ($me->hasPermission('agenda.configurar'))<a class="btn btn-sm" href="{{ route('doctors.schedule.index', $doctor) }}">Configurar agenda</a>@endif
        </div>
        <div class="card__body">
            @forelse ($board['periods'] as $period)
                @php $t = $period['template']; @endphp
                <div class="period">
                    <div class="spread period__head">
                        <strong>{{ substr($t->start_time, 0, 5) }}–{{ substr($t->end_time, 0, 5) }}</strong>
                        <span class="small">
                            <span class="badge {{ $period['full'] ? 'badge-danger' : 'badge-success' }}">{{ $period['booked'] }}/{{ $period['capacity'] }} pacientes</span>
                            @if ($t->max_overbooks)<span class="badge">encaixes {{ $period['overbooks'] }}/{{ $t->max_overbooks }}</span>@endif
                            @if ($t->room)<span class="badge">{{ $t->room->label() }}</span>@endif
                        </span>
                    </div>
                    <div class="slots">
                        @foreach ($period['slots'] as $slot)
                            @php $appt = $appts->first(fn ($a) => ! $a->is_overbook && $a->starts_at->equalTo($slot->start)); @endphp
                            <div class="slot slot--{{ $appt ? 'appt' : $slot->status }}">
                                <span class="slot__time">{{ $slot->start->setTimezone($tz)->format('H:i') }}</span>
                                @if ($appt)
                                    <a class="slot__who" href="{{ route('agenda.show', $appt) }}">{{ $appt->patient?->displayName() }}</a>
                                    @include('agenda._status', ['status' => $appt->status])
                                @elseif ($slot->isFree() && $canBook)
                                    <a class="slot__who slot__free" href="{{ route('agenda.create', ['doctor_id' => $doctor->id, 'branch_id' => $branch->id, 'starts_at' => $slot->start->toIso8601String()]) }}">+ agendar</a>
                                @else
                                    <span class="slot__who muted small">{{ ['free' => 'livre', 'booked' => 'ocupado', 'blocked' => $slot->reason ?? 'bloqueado', 'past' => '—', 'full' => 'limite atingido'][$slot->status] ?? $slot->status }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @php $overbooks = $appts->filter(fn ($a) => $a->is_overbook && $a->template_id === $t->id); @endphp
                    @foreach ($overbooks as $appt)
                        <div class="slot slot--appt slot--overbook"><span class="slot__time">{{ $appt->starts_at->setTimezone($tz)->format('H:i') }}</span>
                            <a class="slot__who" href="{{ route('agenda.show', $appt) }}">{{ $appt->patient?->displayName() }}</a>
                            <span class="badge badge-warning">encaixe</span> @include('agenda._status', ['status' => $appt->status])</div>
                    @endforeach
                    @if ($canOverbook && $t->max_overbooks > $period['overbooks'] && collect($period['slots'])->contains(fn ($s) => ! in_array($s->status, ['past', 'blocked'], true)))
                        @php $firstOpen = collect($period['slots'])->first(fn ($s) => ! in_array($s->status, ['past', 'blocked'], true)); @endphp
                        <a class="btn btn-sm mt-1" href="{{ route('agenda.create', ['doctor_id' => $doctor->id, 'branch_id' => $branch->id, 'starts_at' => $firstOpen->start->toIso8601String(), 'overbook' => 1]) }}">+ Encaixe</a>
                    @endif
                </div>
            @empty
                <p class="muted small">Sem grade neste dia; agendamentos existentes abaixo.</p>
            @endforelse
        </div>
    </section>
@empty
    <section class="card"><div class="empty">Nenhum médico com agenda nesta unidade/dia.
        @if ($me->hasPermission('agenda.configurar'))<br><a href="{{ route('doctors.index') }}">Configure a grade dos médicos</a>.@endif</div></section>
@endforelse
@endsection
