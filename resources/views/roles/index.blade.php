@extends('layouts.app', ['title' => 'Perfis de acesso'])

@section('content')
<div class="page-head">
    <div><h1>Perfis de acesso</h1><p>Conjuntos de permissões atribuídos aos usuários (por filial ou para toda a empresa).</p></div>
    @if (auth()->user()->hasPermission('perfil.gerenciar'))
        <a class="btn btn-primary" href="{{ route('roles.create') }}"><svg><use href="#i-plus"/></svg>Novo perfil</a>
    @endif
</div>

<section class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Perfil</th><th class="hide-sm">Descrição</th><th>Permissões</th><th>Usuários</th><th></th></tr></thead>
            <tbody>
            @foreach ($roles as $r)
                <tr>
                    <td><strong>{{ $r->name }}</strong>
                        @if ($r->is_locked)<span class="badge badge-warning">protegido</span>@elseif ($r->is_system)<span class="badge">padrão</span>@endif
                        <div class="small muted mono">{{ $r->key }}</div></td>
                    <td class="hide-sm text-2">{{ $r->description }}</td>
                    <td>{{ $r->permissions_count }}</td>
                    <td>{{ $r->assignments_count }}</td>
                    <td class="actions">
                        <a class="btn btn-sm" href="{{ route('roles.edit', $r) }}">{{ $r->is_locked ? 'Ver' : 'Editar' }}</a>
                        @if (! $r->is_system && auth()->user()->hasPermission('perfil.gerenciar'))
                            <form method="post" action="{{ route('roles.destroy', $r) }}" class="row" data-confirm="Excluir o perfil {{ $r->name }}?">
                                @csrf @method('delete')<button class="btn btn-sm btn-danger" type="submit">Excluir</button></form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>
@endsection
