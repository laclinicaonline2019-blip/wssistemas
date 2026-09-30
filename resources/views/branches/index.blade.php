@extends('layouts.app', ['title' => 'Filiais'])

@section('content')
<div class="page-head">
    <div><h1>Filiais</h1><p>Matriz e unidades da empresa.</p></div>
    @if (auth()->user()->hasPermission('filial.criar'))
        <a class="btn btn-primary" href="{{ route('branches.create') }}"><svg><use href="#i-plus"/></svg>Nova filial</a>
    @endif
</div>

<section class="card">
    <div class="card__body">
        <form class="toolbar" method="get">
            <div class="field"><label for="search">Buscar</label><input id="search" name="search" class="input" value="{{ request('search') }}" placeholder="Nome ou código"></div>
            <button class="btn" type="submit">Filtrar</button>
        </form>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Filial</th><th class="hide-sm">Endereço</th><th class="hide-sm">Contato</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($branches as $b)
                <tr>
                    <td><strong>{{ $b->name }}</strong> @if ($b->is_headquarters)<span class="badge badge-primary">Matriz</span>@endif
                        <div class="small muted mono">{{ $b->code }}</div></td>
                    <td class="hide-sm text-2">{{ $b->fullAddress() ?: '—' }}</td>
                    <td class="hide-sm text-2">{{ $b->phone ?: '—' }}<div class="small">{{ $b->email }}</div></td>
                    <td>@if ($b->status === 'active')<span class="badge badge-success">Ativa</span>@else<span class="badge">Inativa</span>@endif</td>
                    <td class="actions">
                        @if (auth()->user()->hasPermission('filial.editar', $b->id))
                            <a class="btn btn-sm" href="{{ route('branches.edit', $b) }}">Editar</a>
                        @endif
                        @if (auth()->user()->hasPermission('filial.desativar') && ! $b->is_headquarters)
                            <form method="post" action="{{ route('branches.status', $b) }}" class="row" data-confirm="{{ $b->status === 'active' ? 'Desativar esta filial?' : 'Reativar esta filial?' }}">
                                @csrf @method('patch')
                                <input type="hidden" name="status" value="{{ $b->status === 'active' ? 'inactive' : 'active' }}">
                                <button class="btn btn-sm {{ $b->status === 'active' ? 'btn-danger' : '' }}" type="submit">{{ $b->status === 'active' ? 'Desativar' : 'Reativar' }}</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">Nenhuma filial encontrada.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $branches->links() }}
</section>
@endsection
