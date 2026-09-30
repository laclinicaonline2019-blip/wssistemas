@extends('layouts.app', ['title' => 'Empresas'])

@section('content')
<div class="page-head">
    <div><h1>Empresas clientes</h1><p>Clínicas provisionadas na plataforma.</p></div>
    <a class="btn btn-primary" href="{{ route('platform.companies.create') }}"><svg><use href="#i-plus"/></svg>Nova clínica</a>
</div>
<section class="card">
    <div class="card__body">
        <form class="toolbar" method="get">
            <div class="field"><label for="search">Buscar</label><input id="search" name="search" class="input" value="{{ request('search') }}" placeholder="Nome"></div>
            <div class="field"><label for="status">Status</label>
                <select id="status" name="status" class="input"><option value="">Todos</option>
                    @foreach (['trial' => 'Trial', 'active' => 'Ativa', 'suspended' => 'Suspensa', 'cancelled' => 'Cancelada'] as $k => $l)
                        <option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach
                </select></div>
            <button class="btn" type="submit">Filtrar</button>
        </form>
    </div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Clínica</th><th class="hide-sm">CNPJ</th><th>Plano</th><th>Status</th><th class="hide-sm">Criada em</th><th></th></tr></thead>
        <tbody>
        @forelse ($companies as $c)
            <tr>
                <td><strong>{{ $c->trade_name }}</strong><div class="small muted">{{ $c->legal_name }}</div></td>
                <td class="hide-sm mono small">{{ $c->document }}</td>
                <td>{{ $c->plan?->name ?? '—' }}</td>
                <td>@include('platform._status', ['status' => $c->status])</td>
                <td class="hide-sm small">{{ $c->created_at->format('d/m/Y') }}</td>
                <td class="actions"><a class="btn btn-sm" href="{{ route('platform.companies.show', $c) }}">Abrir</a></td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty">Nenhuma clínica encontrada.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $companies->links() }}
</section>
@endsection
