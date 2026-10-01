@extends('layouts.app', ['title' => 'Atendimento'])

@php
    $me = auth()->user();
    $tz = 'America/Sao_Paulo';
    $canCall = $me->hasPermission('fila.chamar') || $me->hasPermission('fila.gerenciar');
@endphp

@section('content')
<div class="page-head" data-autorefresh="30">
    <div>
        <h1>Meu dia</h1>
        <p>{{ $date->translatedFormat('l, d/m/Y') }}@if ($doctor) · {{ $doctor->displayName() }} · {{ $appointments->whereIn('status', ['arrived', 'in_service'])->count() }} na clínica · atualiza automaticamente @endif</p>
    </div>
    <form method="get" class="row">
        <label class="sr-only" for="ws-date">Data</label>
        <input id="ws-date" type="date" name="date" class="input w-auto" value="{{ $date->toDateString() }}" data-autosubmit>
    </form>
</div>

@unless ($doctor)
    <div class="alert alert-warning">Seu usuário não está vinculado a um cadastro de médico ativo. Peça ao administrador para vincular em <strong>Médicos → Editar → Usuário do sistema</strong>.</div>
@else
    @if ($drafts->isNotEmpty())
        <section class="card mb-2">
            <div class="card__head"><h2>Atendimentos em aberto (rascunho)</h2><span class="badge badge-warning">{{ $drafts->count() }}</span></div>
            <div class="table-wrap"><table class="table"><tbody>
                @foreach ($drafts as $d)
                    <tr>
                        <td><strong>{{ $d->patient->displayName() }}</strong> <span class="record-no small">#{{ $d->patient->record_number }}</span></td>
                        <td class="small muted">iniciado {{ $d->started_at->timezone($tz)->format('d/m H:i') }}{{ $d->draft_saved_at ? ' · salvo '.$d->draft_saved_at->timezone($tz)->format('H:i') : '' }}</td>
                        <td class="actions"><a class="btn btn-sm btn-primary" href="{{ route('encounters.edit', $d) }}">Continuar</a></td>
                    </tr>
                @endforeach
            </tbody></table></div>
        </section>
    @endif

    <section class="card">
        <div class="card__head"><h2>Pacientes agendados</h2></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Horário</th><th>Paciente</th><th class="hide-sm">Serviço / sala</th><th>Situação</th><th></th></tr></thead>
            <tbody>
            @forelse ($appointments as $a)
                @php $enc = $encounters[$a->id] ?? null; $t = $a->ticket; @endphp
                <tr class="{{ in_array($a->status, ['completed', 'no_show'], true) ? 'muted' : '' }}">
                    <td class="nowrap"><strong>{{ $a->starts_at->timezone($tz)->format('H:i') }}</strong>
                        @if ($a->is_overbook)<div class="small"><span class="badge badge-warning">encaixe</span></div>@endif</td>
                    <td>{{ $a->patient->displayName() }} <span class="record-no small">#{{ $a->patient->record_number }}</span>
                        <div class="small muted">{{ $a->patient->age() !== null ? $a->patient->age().' anos' : '' }}{{ $t ? ' · senha '.$t->code : '' }}</div></td>
                    <td class="hide-sm small">{{ $a->service?->name ?? 'Consulta' }}{{ $a->room ? ' · '.$a->room->label() : '' }}</td>
                    <td>@include('agenda._status', ['status' => $a->status])
                        @if ($t && $t->status === 'called')<div class="small muted">chamado</div>@endif</td>
                    <td class="actions"><div class="row">
                        @if ($canCall && $t && in_array($t->status, ['waiting', 'called'], true))
                            <form method="post" action="{{ route('workspace.call', $a) }}">@csrf<button class="btn btn-sm" type="submit">{{ $t->status === 'called' ? 'Chamar de novo' : 'Chamar' }}</button></form>
                        @endif
                        @if ($enc && $enc->isDraft())
                            <a class="btn btn-sm btn-primary" href="{{ route('encounters.edit', $enc) }}">Continuar</a>
                        @elseif ($enc)
                            <a class="btn btn-sm" href="{{ route('encounters.show', $enc) }}">Ver registro</a>
                        @elseif (in_array($a->status, ['scheduled', 'confirmed', 'arrived', 'in_service'], true))
                            <form method="post" action="{{ route('workspace.start', $a) }}">@csrf<button class="btn btn-sm btn-primary" type="submit">Iniciar atendimento</button></form>
                        @endif
                    </div></td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">Nenhum paciente agendado para este dia.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
    <p class="help mt-1">Atendimento sem agendamento (demanda espontânea): abra a ficha do paciente e use <strong>Iniciar atendimento avulso</strong>.</p>
@endunless
@endsection
