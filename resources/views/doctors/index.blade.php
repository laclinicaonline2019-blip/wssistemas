@extends('layouts.app', ['title' => 'Médicos'])

@section('content')
<div class="page-head">
    <div><h1>Médicos</h1><p>Corpo clínico, especialidades e unidades de atendimento.</p></div>
    @if (auth()->user()->hasPermission('medico.gerenciar'))
        <a class="btn btn-primary" href="{{ route('doctors.create') }}"><svg><use href="#i-plus"/></svg>Novo médico</a>
    @endif
</div>

<section class="card">
    <div class="card__body">
        <form class="toolbar" method="get">
            <div class="field grow"><label for="search">Buscar</label><input id="search" name="search" class="input" value="{{ request('search') }}" placeholder="Nome ou CRM"></div>
            <div class="field"><label for="specialty_id">Especialidade</label>
                <select id="specialty_id" name="specialty_id" class="input"><option value="">Todas</option>
                    @foreach ($specialties as $s)<option value="{{ $s->id }}" @selected(request('specialty_id') === $s->id)>{{ $s->name }}</option>@endforeach
                </select></div>
            <div class="field"><label for="branch_id">Unidade</label>
                <select id="branch_id" name="branch_id" class="input"><option value="">Todas</option>
                    @foreach ($branches as $b)<option value="{{ $b->id }}" @selected(request('branch_id') === $b->id)>{{ $b->name }}</option>@endforeach
                </select></div>
            <div class="field"><label for="status">Situação</label>
                <select id="status" name="status" class="input"><option value="">Todos</option>
                    <option value="active" @selected(request('status') === 'active')>Ativos</option>
                    <option value="inactive" @selected(request('status') === 'inactive')>Inativos</option>
                </select></div>
            <button class="btn" type="submit">Filtrar</button>
        </form>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Médico</th><th>Especialidades</th><th class="hide-sm">Unidades</th><th>Situação</th><th></th></tr></thead>
            <tbody>
            @forelse ($doctors as $d)
                <tr>
                    <td><strong>{{ $d->displayName() }}</strong><div class="small muted">{{ $d->registration() }}</div></td>
                    <td><div class="chips">@forelse ($d->specialties as $s)<span class="badge badge-primary">{{ $s->name }}</span>@empty<span class="muted small">—</span>@endforelse</div></td>
                    <td class="hide-sm small">{{ $d->branches->pluck('name')->implode(', ') ?: '—' }}</td>
                    <td>@if ($d->status === 'active')<span class="badge badge-success">Ativo</span>@else<span class="badge">Inativo</span>@endif</td>
                    <td class="actions"><a class="btn btn-sm" href="{{ route('doctors.edit', $d) }}">Abrir</a></td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">Nenhum médico encontrado.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $doctors->links() }}
</section>
@endsection
