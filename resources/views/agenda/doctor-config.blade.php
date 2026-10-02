@extends('layouts.app', ['title' => 'Agenda de '.$doctor->displayName()])

@php use App\Modules\Scheduling\Models\ScheduleTemplate; @endphp

@section('content')
<div class="page-head">
    <div><h1>Agenda — {{ $doctor->displayName() }}</h1><p>{{ $doctor->registration() }} · grade semanal, tipos de atendimento, limites e bloqueios.</p></div>
    <div class="row"><a class="btn" href="{{ route('agenda.index', ['doctor_id' => $doctor->id]) }}">Ver agenda</a><a class="btn" href="{{ route('doctors.edit', $doctor) }}">Cadastro</a></div>
</div>

@if (session('affected'))
    <div class="alert alert-warning"><strong>Atenção:</strong> existem agendamentos no período bloqueado. Remarque ou avise os pacientes:
        <ul class="mb-0">@foreach (session('affected') as $a)<li><a href="{{ route('agenda.show', $a['id']) }}">{{ $a['when'] }} — {{ $a['patient'] }}</a> {{ \App\Core\Support\Format::phone($a['phone']) }}</li>@endforeach</ul></div>
@endif

@if ($doctor->branches->isEmpty())
    <div class="alert alert-warning">Vincule ao menos uma unidade no cadastro do médico para montar a grade.</div>
@endif

<section class="card">
    <div class="card__head"><h2>Grade semanal</h2></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Dia</th><th>Horário</th><th>Unidade / sala</th><th>Duração</th><th>Limite</th><th>Encaixes</th><th class="hide-sm">Vigência</th><th></th></tr></thead>
        <tbody>
        @forelse ($doctor->scheduleTemplates as $t)
            <tr class="{{ $t->is_active ? '' : 'muted' }}">
                <td>{{ ScheduleTemplate::WEEKDAYS[$t->weekday] }}</td>
                <td class="nowrap">{{ substr($t->start_time, 0, 5) }}–{{ substr($t->end_time, 0, 5) }}</td>
                <td>{{ $t->branch?->name }}{{ $t->room ? ' · '.$t->room->label() : '' }}{{ $t->specialty ? ' · '.$t->specialty->name : '' }}</td>
                <td>{{ $t->slot_minutes }} min</td>
                <td>{{ $t->capacity() }} <span class="small muted">({{ $t->slotCount() }} horários)</span></td>
                <td>{{ $t->max_overbooks }}</td>
                <td class="hide-sm small">{{ $t->valid_from?->format('d/m/Y') ?? 'desde sempre' }} → {{ $t->valid_until?->format('d/m/Y') ?? 'sem fim' }}</td>
                <td class="actions"><form method="post" action="{{ route('doctors.schedule.templates.toggle', [$doctor, $t]) }}">@csrf @method('patch')
                    <button class="btn btn-sm" type="submit">{{ $t->is_active ? 'Desativar' : 'Reativar' }}</button></form></td>
            </tr>
        @empty
            <tr><td colspan="8" class="empty">Nenhum período cadastrado — o médico ainda não aparece para agendamento.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <form method="post" action="{{ route('doctors.schedule.templates.store', $doctor) }}" class="card__body form-grid">
        @csrf
        <h3 class="col-12 mb-0">Adicionar período</h3>
        <div class="field col-2"><label for="t-weekday">Dia</label>
            <select id="t-weekday" name="weekday" class="input">@foreach (ScheduleTemplate::WEEKDAYS as $k => $l)<option value="{{ $k }}" @selected((string) old('weekday', 1) === (string) $k)>{{ $l }}</option>@endforeach</select></div>
        <x-field name="start_time" label="Início" type="time" col="col-2" value="08:00" required />
        <x-field name="end_time" label="Fim" type="time" col="col-2" value="12:00" required />
        <x-field name="slot_minutes" label="Duração (min)" type="number" col="col-2" value="20" min="5" max="240" required />
        <x-field name="max_patients" label="Limite de pacientes" type="number" col="col-2" min="1" help="Vazio = nº de horários" />
        <x-field name="max_overbooks" label="Encaixes" type="number" col="col-2" value="0" min="0" max="50" />
        <div class="field col-4"><label for="t-branch">Unidade</label>
            <select id="t-branch" name="branch_id" class="input">@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select></div>
        <div class="field col-4"><label for="t-room">Sala</label>
            <select id="t-room" name="room_id" class="input"><option value="">—</option>@foreach ($rooms as $r)<option value="{{ $r->id }}">{{ $r->label() }} ({{ $branches->firstWhere('id', $r->branch_id)?->name }})</option>@endforeach</select></div>
        <div class="field col-4"><label for="t-spec">Especialidade do período</label>
            <select id="t-spec" name="specialty_id" class="input"><option value="">Todas do médico</option>@foreach ($doctor->specialties as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></div>
        <x-field name="valid_from" label="Vigente a partir de" type="date" col="col-3" />
        <x-field name="valid_until" label="Até" type="date" col="col-3" />
        <div class="col-6 form-actions"><button class="btn btn-primary" type="submit" @disabled($branches->isEmpty())>Adicionar à grade</button></div>
    </form>
</section>

<div class="grid grid-2">
    <section class="card">
        <div class="card__head"><h2>Tipos de atendimento e valores</h2></div>
        <div class="card__body stack">
            @foreach ($doctor->services as $s)
                <form method="post" action="{{ route('doctors.schedule.services.update', [$doctor, $s]) }}" class="form-grid service-row">
                    @csrf @method('put')
                    <div class="field col-4"><label class="label" for="sn-{{ $s->id }}">Nome</label><input id="sn-{{ $s->id }}" name="name" class="input" value="{{ $s->name }}" required></div>
                    <div class="field col-3"><label class="label" for="sp-{{ $s->id }}">Valor (R$)</label><input id="sp-{{ $s->id }}" name="price" class="input" value="{{ number_format($s->price_cents / 100, 2, ',', '.') }}" inputmode="decimal"></div>
                    <div class="field col-2"><label class="label" for="sd-{{ $s->id }}">Min</label><input id="sd-{{ $s->id }}" name="duration_minutes" type="number" class="input" value="{{ $s->duration_minutes }}" min="5"></div>
                    <div class="col-3 form-actions"><button class="btn btn-sm" type="submit">Salvar</button></div>
                    <div class="field col-12"><label class="label" for="spr-{{ $s->id }}">Procedimento faturado no convênio (TUSS)</label>
                        <select id="spr-{{ $s->id }}" name="procedure_id" class="input"><option value="">—</option>@foreach ($procedures as $p)<option value="{{ $p->id }}" @selected($s->procedure_id === $p->id)>{{ $p->code }} — {{ $p->name }}</option>@endforeach</select></div>
                    <div class="col-12 row small">
                        <label class="check"><input type="checkbox" name="accepts_private" value="1" @checked($s->accepts_private)><span>Particular</span></label>
                        <label class="check"><input type="checkbox" name="accepts_insurance" value="1" @checked($s->accepts_insurance)><span>Convênio</span></label>
                        <label class="check"><input type="checkbox" name="is_return" value="1" @checked($s->is_return)><span>Retorno</span></label>
                        <label class="check"><input type="checkbox" name="is_telemedicine" value="1" @checked($s->is_telemedicine)><span>Telemedicina</span></label>
                        <label class="check"><input type="checkbox" name="is_active" value="1" @checked($s->is_active)><span>Ativo</span></label>
                    </div>
                </form>
            @endforeach
            <form method="post" action="{{ route('doctors.schedule.services.store', $doctor) }}" class="form-grid">
                @csrf
                <h3 class="col-12 mb-0">Novo tipo</h3>
                <x-field name="name" label="Nome" col="col-5" placeholder="Consulta, Retorno…" required />
                <x-field name="price" label="Valor particular (R$)" col="col-4" placeholder="250,00" inputmode="decimal" />
                <x-field name="duration_minutes" label="Duração (min)" type="number" col="col-3" help="Vazio = da grade" />
                <div class="field col-12"><label class="label" for="spr-new">Procedimento faturado no convênio (TUSS)</label>
                    <select id="spr-new" name="procedure_id" class="input"><option value="">—</option>@foreach ($procedures as $p)<option value="{{ $p->id }}">{{ $p->code }} — {{ $p->name }}</option>@endforeach</select></div>
                <div class="col-12 row small">
                    <label class="check"><input type="checkbox" name="accepts_private" value="1" checked><span>Particular</span></label>
                    <label class="check"><input type="checkbox" name="accepts_insurance" value="1"><span>Convênio</span></label>
                    <label class="check"><input type="checkbox" name="is_return" value="1"><span>Retorno</span></label>
                    <label class="check"><input type="checkbox" name="is_telemedicine" value="1"><span>Telemedicina</span></label>
                </div>
                <div class="col-12 form-actions"><button class="btn btn-primary btn-sm" type="submit">Adicionar</button></div>
            </form>
        </div>
    </section>

    <div>
        <section class="card">
            <div class="card__head"><h2>Limite diário</h2></div>
            <form method="post" action="{{ route('doctors.schedule.daily_limit', $doctor) }}" class="card__body row">
                @csrf @method('put')
                <label class="sr-only" for="daily_limit">Limite diário</label>
                <input id="daily_limit" name="daily_limit" type="number" min="1" max="200" class="input w-auto" value="{{ $doctor->daily_limit }}" placeholder="Sem limite">
                <span class="small muted">pacientes/dia somando todas as unidades (encaixes à parte)</span>
                <button class="btn btn-sm" type="submit">Salvar</button>
            </form>
        </section>

        <section class="card">
            <div class="card__head"><h2>Férias e bloqueios</h2></div>
            <div class="card__body stack">
                @forelse ($blocks as $b)
                    <div class="spread small"><span><span class="badge">{{ \App\Modules\Scheduling\Models\ScheduleBlock::TYPES[$b->type] ?? $b->type }}</span>
                        {{ $b->starts_at->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i') }} → {{ $b->ends_at->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i') }} · {{ $b->reason }}</span>
                        <form method="post" action="{{ route('agenda.blocks.destroy', $b) }}" data-confirm="Remover este bloqueio?">@csrf @method('delete')<button class="btn btn-ghost btn-sm" type="submit">Remover</button></form></div>
                @empty
                    <p class="small muted">Nenhum bloqueio futuro.</p>
                @endforelse
                <form method="post" action="{{ route('agenda.blocks.store') }}" class="form-grid">
                    @csrf
                    <input type="hidden" name="doctor_id" value="{{ $doctor->id }}">
                    <div class="field col-6"><label for="b-type">Tipo</label>
                        <select id="b-type" name="type" class="input">@foreach (\App\Modules\Scheduling\Models\ScheduleBlock::TYPES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div class="field col-6"><label for="b-branch">Unidade</label>
                        <select id="b-branch" name="branch_id" class="input"><option value="">Todas</option>@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select></div>
                    <x-field name="starts_at" label="Início" type="datetime-local" col="col-6" required />
                    <x-field name="ends_at" label="Fim" type="datetime-local" col="col-6" required />
                    <x-field name="reason" label="Motivo" col="col-12" required maxlength="200" />
                    <div class="col-12 form-actions"><button class="btn btn-sm btn-primary" type="submit">Bloquear período</button></div>
                </form>
            </div>
        </section>
    </div>
</div>
@endsection
