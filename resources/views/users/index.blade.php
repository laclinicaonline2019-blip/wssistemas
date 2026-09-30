@extends('layouts.app', ['title' => 'Usuários'])

@section('content')
<div class="page-head">
    <div><h1>Usuários</h1><p>Equipe com acesso ao sistema e seus perfis por filial.</p></div>
    @if (auth()->user()->hasPermission('usuario.criar'))
        <a class="btn btn-primary" href="{{ route('users.create') }}"><svg><use href="#i-plus"/></svg>Novo usuário</a>
    @endif
</div>

<section class="card">
    <div class="card__body">
        <form class="toolbar" method="get">
            <div class="field"><label for="search">Buscar</label><input id="search" name="search" class="input" value="{{ request('search') }}" placeholder="Nome ou e-mail"></div>
            <div class="field"><label for="status">Status</label>
                <select id="status" name="status" class="input">
                    <option value="">Todos</option>
                    <option value="active" @selected(request('status') === 'active')>Ativos</option>
                    <option value="blocked" @selected(request('status') === 'blocked')>Bloqueados</option>
                </select></div>
            <button class="btn" type="submit">Filtrar</button>
        </form>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Usuário</th><th>Perfis</th><th class="hide-sm">Segurança</th><th class="hide-sm">Último acesso</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($users as $u)
                <tr>
                    <td><strong>{{ $u->name }}</strong><div class="small muted">{{ $u->email }}</div></td>
                    <td>
                        @forelse ($u->roleAssignments as $a)
                            <div class="small"><span class="badge badge-primary">{{ $a->role?->name }}</span> <span class="muted">{{ $a->branch?->name ?? 'todas as filiais' }}</span></div>
                        @empty
                            <span class="muted small">Sem perfil</span>
                        @endforelse
                    </td>
                    <td class="hide-sm">
                        @if ($u->hasTwoFactorEnabled())<span class="badge badge-success">2FA</span>@else<span class="badge badge-warning">sem 2FA</span>@endif
                        @if ($u->isLocked())<span class="badge badge-danger">bloqueio temporário</span>@endif
                    </td>
                    <td class="hide-sm small text-2">{{ $u->last_login_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? 'nunca' }}</td>
                    <td>@if ($u->status === 'active')<span class="badge badge-success">Ativo</span>@else<span class="badge badge-danger">Bloqueado</span>@endif</td>
                    <td class="actions"><a class="btn btn-sm" href="{{ route('users.edit', $u) }}">Abrir</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">Nenhum usuário encontrado.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $users->links() }}
</section>
@endsection
