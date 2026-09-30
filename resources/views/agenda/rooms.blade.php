@extends('layouts.app', ['title' => 'Salas'])

@php use App\Modules\Scheduling\Models\Room; @endphp

@section('content')
<div class="page-head"><div><h1>Salas e consultórios</h1><p>Usadas na grade dos médicos e nas chamadas do painel.</p></div></div>

<div class="grid grid-3">
    <section class="card span-2">
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Sala</th><th>Unidade</th><th class="hide-sm">Preferencial</th><th>Situação</th><th></th></tr></thead>
            <tbody>
            @forelse ($rooms as $r)
                <tr><td colspan="5">
                    <form method="post" action="{{ route('rooms.update', $r) }}" class="row">
                        @csrf @method('put')
                        <input type="hidden" name="branch_id" value="{{ $r->branch_id }}">
                        <label class="sr-only" for="rn-{{ $r->id }}">Nome</label><input id="rn-{{ $r->id }}" name="name" class="input w-auto" value="{{ $r->name }}" required>
                        <label class="sr-only" for="rnum-{{ $r->id }}">Número</label><input id="rnum-{{ $r->id }}" name="number" class="input input-sm" value="{{ $r->number }}" placeholder="nº">
                        <span class="small muted">{{ $r->branch?->name }}</span>
                        <label class="sr-only" for="rd-{{ $r->id }}">Médico</label>
                        <select id="rd-{{ $r->id }}" name="doctor_id" class="input input-sm"><option value="">sem médico fixo</option>@foreach ($doctors as $d)<option value="{{ $d->id }}" @selected($r->doctor_id === $d->id)>{{ $d->displayName() }}</option>@endforeach</select>
                        <label class="sr-only" for="rs-{{ $r->id }}">Situação</label>
                        <select id="rs-{{ $r->id }}" name="status" class="input input-sm">@foreach (Room::STATUSES as $k => $l)<option value="{{ $k }}" @selected($r->status === $k)>{{ $l }}</option>@endforeach</select>
                        <button class="btn btn-sm" type="submit">Salvar</button>
                    </form>
                </td></tr>
            @empty
                <tr><td colspan="5" class="empty">Nenhuma sala cadastrada.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    <form method="post" action="{{ route('rooms.store') }}" class="card">
        @csrf
        <div class="card__head"><h2>Nova sala</h2></div>
        <div class="card__body form-grid">
            <div class="field col-12"><label for="n-branch">Unidade</label>
                <select id="n-branch" name="branch_id" class="input">@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select></div>
            <x-field name="name" label="Nome" col="col-8" required placeholder="Consultório" />
            <x-field name="number" label="Número" col="col-4" placeholder="03" />
            <div class="field col-12"><label for="n-spec">Especialidade</label>
                <select id="n-spec" name="specialty_id" class="input"><option value="">—</option>@foreach ($specialties as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></div>
            <x-field name="equipment" label="Equipamentos" col="col-12" placeholder="ECG, maca, dermatoscópio…" />
            <div class="col-12 form-actions"><button class="btn btn-primary" type="submit">Cadastrar</button></div>
        </div>
    </form>
</div>
@endsection
