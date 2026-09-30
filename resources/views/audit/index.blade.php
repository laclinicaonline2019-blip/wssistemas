@extends('layouts.app', ['title' => 'Auditoria'])

@section('content')
<div class="page-head">
    <div><h1>Auditoria</h1><p>Trilha imutável de eventos: quem, quando, de onde, o quê e o resultado.</p></div>
    @if (auth()->user()->hasPermission('auditoria.exportar'))
        <a class="btn" href="{{ route('audit.export', request()->query()) }}">Exportar CSV</a>
    @endif
</div>

<section class="card">
    <div class="card__body">
        <form class="toolbar" method="get">
            <div class="field"><label for="from">De</label><input id="from" type="date" name="from" class="input" value="{{ $filters['from'] ?? '' }}"></div>
            <div class="field"><label for="to">Até</label><input id="to" type="date" name="to" class="input" value="{{ $filters['to'] ?? '' }}"></div>
            <div class="field"><label for="user_id">Usuário</label>
                <select id="user_id" name="user_id" class="input"><option value="">Todos</option>
                    @foreach ($users as $u)<option value="{{ $u->id }}" @selected(($filters['user_id'] ?? '') === $u->id)>{{ $u->name }}</option>@endforeach
                </select></div>
            <div class="field"><label for="branch_id">Filial</label>
                <select id="branch_id" name="branch_id" class="input"><option value="">Todas</option>
                    @foreach ($branches as $b)<option value="{{ $b->id }}" @selected(($filters['branch_id'] ?? '') === $b->id)>{{ $b->name }}</option>@endforeach
                </select></div>
            <div class="field"><label for="action">Ação (prefixo)</label><input id="action" name="action" class="input" value="{{ $filters['action'] ?? '' }}" placeholder="ex.: auth. / user."></div>
            <div class="field"><label for="result">Resultado</label>
                <select id="result" name="result" class="input"><option value="">Todos</option>
                    @foreach (['success' => 'Sucesso', 'failure' => 'Falha', 'denied' => 'Negado'] as $k => $l)<option value="{{ $k }}" @selected(($filters['result'] ?? '') === $k)>{{ $l }}</option>@endforeach
                </select></div>
            <button class="btn" type="submit">Filtrar</button>
            <a class="btn btn-ghost" href="{{ route('audit.index') }}">Limpar</a>
        </form>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Data/hora</th><th>Usuário</th><th>Ação</th><th class="hide-sm">Registro</th><th class="hide-sm">IP</th><th>Detalhes</th></tr></thead>
            <tbody>
            @forelse ($logs as $log)
                <tr>
                    <td class="nowrap small">{{ $log->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s') }}</td>
                    <td>{{ $log->user?->name ?? ucfirst($log->actor_type) }}</td>
                    <td>@include('partials.audit-action', ['log' => $log])<div class="small muted mono">{{ $log->action }}</div></td>
                    <td class="hide-sm small">{{ $log->auditable_type }}<div class="muted mono">{{ $log->auditable_id }}</div></td>
                    <td class="hide-sm small mono">{{ $log->ip_address }}</td>
                    <td>
                        @if ($log->old_values || $log->new_values || $log->metadata)
                            <details class="diff"><summary>ver</summary>
                                @if ($log->old_values)<div class="small muted">Anterior</div><pre>{{ json_encode($log->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>@endif
                                @if ($log->new_values)<div class="small muted">Novo</div><pre>{{ json_encode($log->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>@endif
                                @if ($log->metadata)<div class="small muted">Contexto</div><pre>{{ json_encode($log->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>@endif
                                <div class="small muted mono">req {{ $log->request_id }}</div>
                            </details>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">Nenhum evento para os filtros informados.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $logs->links() }}
</section>
@endsection
