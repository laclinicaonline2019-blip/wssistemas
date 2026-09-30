@extends('layouts.app', ['title' => 'Feriados e bloqueios'])

@section('content')
<div class="page-head"><div><h1>Feriados e bloqueios de unidade</h1><p>Dias sem atendimento. Bloqueios individuais de médicos ficam na agenda de cada médico.</p></div></div>

@if (session('affected'))
    <div class="alert alert-warning"><strong>Atenção:</strong> agendamentos no período bloqueado:
        <ul class="mb-0">@foreach (session('affected') as $a)<li><a href="{{ route('agenda.show', $a['id']) }}">{{ $a['when'] }} — {{ $a['patient'] }}</a></li>@endforeach</ul></div>
@endif

<div class="grid grid-2">
    <section class="card">
        <div class="card__head"><h2>Feriados</h2></div>
        <div class="table-wrap"><table class="table"><tbody>
            @forelse ($holidays as $h)
                <tr><td class="nowrap">{{ $h->date->format('d/m/Y') }}</td><td>{{ $h->name }}</td><td class="small muted">{{ $h->branch?->name ?? 'Todas as unidades' }}</td>
                    <td class="actions"><form method="post" action="{{ route('agenda.holidays.destroy', $h) }}" data-confirm="Remover feriado?">@csrf @method('delete')<button class="btn btn-ghost btn-sm" type="submit">Remover</button></form></td></tr>
            @empty
                <tr><td class="empty">Nenhum feriado cadastrado.</td></tr>
            @endforelse
        </tbody></table></div>
        <form method="post" action="{{ route('agenda.holidays.store') }}" class="card__body form-grid">
            @csrf
            <x-field name="date" label="Data" type="date" col="col-4" required />
            <x-field name="name" label="Nome" col="col-8" required placeholder="Ex.: Aniversário da cidade" />
            <div class="field col-8"><label for="h-branch">Unidade</label>
                <select id="h-branch" name="branch_id" class="input"><option value="">Todas as unidades</option>@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select></div>
            <div class="col-4 form-actions"><button class="btn btn-primary" type="submit">Adicionar</button></div>
        </form>
    </section>

    <section class="card">
        <div class="card__head"><h2>Bloqueios da unidade</h2></div>
        <div class="card__body stack">
            @forelse ($blocks as $b)
                <div class="spread small"><span>{{ $b->starts_at->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i') }} → {{ $b->ends_at->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i') }} · {{ $b->branch?->name ?? 'todas' }} · {{ $b->reason }}</span>
                    <form method="post" action="{{ route('agenda.blocks.destroy', $b) }}" data-confirm="Remover bloqueio?">@csrf @method('delete')<button class="btn btn-ghost btn-sm" type="submit">Remover</button></form></div>
            @empty
                <p class="small muted">Nenhum bloqueio de unidade.</p>
            @endforelse
            <form method="post" action="{{ route('agenda.blocks.store') }}" class="form-grid">
                @csrf
                <input type="hidden" name="type" value="maintenance">
                <div class="field col-12"><label for="bb-branch">Unidade</label>
                    <select id="bb-branch" name="branch_id" class="input">@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select></div>
                <x-field name="starts_at" label="Início" type="datetime-local" col="col-6" required />
                <x-field name="ends_at" label="Fim" type="datetime-local" col="col-6" required />
                <x-field name="reason" label="Motivo" col="col-12" required />
                <div class="col-12 form-actions"><button class="btn btn-primary btn-sm" type="submit">Bloquear unidade</button></div>
            </form>
        </div>
    </section>
</div>
@endsection
