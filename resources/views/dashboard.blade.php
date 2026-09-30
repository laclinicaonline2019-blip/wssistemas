@extends('layouts.app', ['title' => 'Dashboard'])

@section('content')
<div class="page-head">
    <div>
        <h1>Olá, {{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') }}</h1>
        <p>{{ $company->trade_name }} · plano {{ $company->plan?->name ?? 'sem plano' }}
            @if ($company->status === 'trial')
                · <span class="badge badge-info">Trial até {{ $company->trial_ends_at?->timezone('America/Sao_Paulo')->format('d/m/Y') }}</span>
            @endif
        </p>
    </div>
</div>

@if ($patients || $doctorsActive !== null)
<div class="grid grid-4 mb-2">
    @if ($patients)
        <a class="card kpi kpi-link" href="{{ route('patients.index') }}"><div class="kpi__label">Pacientes ativos</div><div class="kpi__value">{{ number_format($patients['active'], 0, ',', '.') }}</div><div class="kpi__hint">cadastro da empresa</div></a>
        <div class="card kpi"><div class="kpi__label">Novos pacientes no mês</div><div class="kpi__value">{{ $patients['new_month'] }}</div><div class="kpi__hint">desde {{ now()->startOfMonth()->format('d/m') }}</div></div>
    @endif
    @if ($doctorsActive !== null)
        <a class="card kpi kpi-link" href="{{ route('doctors.index') }}"><div class="kpi__label">Médicos ativos</div><div class="kpi__value">{{ $doctorsActive }}</div><div class="kpi__hint">nas suas unidades</div></a>
    @endif
    @if (auth()->user()->hasPermission('paciente.criar'))
        <a class="card kpi kpi-link kpi-action" href="{{ route('patients.create') }}"><div class="kpi__label">Ação rápida</div><div class="kpi__value">+ Paciente</div><div class="kpi__hint">novo cadastro</div></a>
    @endif
</div>
@endif

<div class="grid grid-4">
    <div class="card kpi">
        <div class="kpi__label">Filiais ativas</div>
        <div class="kpi__value">{{ $branches->where('status', 'active')->count() }}</div>
        @php $u = $usage['max_branches']; @endphp
        <div class="kpi__hint">{{ $u['used'] }} de {{ $u['limit'] ?? '∞' }} no plano</div>
        @if ($u['limit'])<div class="meter"><span data-pct="{{ min(100, round($u['used'] / $u['limit'] * 100)) }}"></span></div>@endif
    </div>
    <div class="card kpi">
        <div class="kpi__label">Usuários</div>
        <div class="kpi__value">{{ $usersCount }}</div>
        @php $u = $usage['max_users']; @endphp
        <div class="kpi__hint">{{ $u['used'] }} de {{ $u['limit'] ?? '∞' }} no plano</div>
        @if ($u['limit'])<div class="meter"><span data-pct="{{ min(100, round($u['used'] / $u['limit'] * 100)) }}"></span></div>@endif
    </div>
    <div class="card kpi">
        <div class="kpi__label">Usuários sem 2FA</div>
        <div class="kpi__value">{{ $usersWithout2fa }}</div>
        <div class="kpi__hint">Recomendado ativar para todos</div>
    </div>
    <div class="card kpi">
        <div class="kpi__label">Falhas de login (24h)</div>
        <div class="kpi__value">{{ $failedLogins ?? '—' }}</div>
        <div class="kpi__hint">{{ $failedLogins === null ? 'Requer permissão de auditoria' : 'Monitoramento de segurança' }}</div>
    </div>
</div>

<div class="grid grid-2 mt-2">
    <section class="card">
        <div class="card__head"><h2>Unidades</h2>
            @if (auth()->user()->hasPermission('filial.visualizar'))<a class="btn btn-sm" href="{{ route('branches.index') }}">Gerenciar</a>@endif
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Unidade</th><th class="hide-sm">Cidade</th><th>Status</th></tr></thead>
                <tbody>
                @forelse ($branches as $b)
                    <tr>
                        <td><strong>{{ $b->name }}</strong> @if ($b->is_headquarters)<span class="badge badge-primary">Matriz</span>@endif<div class="small muted">{{ $b->code }}</div></td>
                        <td class="hide-sm">{{ $b->city ? $b->city.' / '.$b->state : '—' }}</td>
                        <td>@if ($b->status === 'active')<span class="badge badge-success">Ativa</span>@else<span class="badge">Inativa</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="empty">Nenhuma unidade.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <div class="card__head"><h2>Atividade recente</h2>
            @if ($recent->isNotEmpty())<a class="btn btn-sm" href="{{ route('audit.index') }}">Auditoria</a>@endif
        </div>
        <div class="table-wrap">
            <table class="table">
                <tbody>
                @forelse ($recent as $log)
                    <tr>
                        <td>@include('partials.audit-action', ['log' => $log])<div class="small muted">{{ $log->user?->name ?? 'Sistema' }}</div></td>
                        <td class="small muted nowrap">{{ $log->created_at->timezone('America/Sao_Paulo')->format('d/m H:i') }}</td>
                    </tr>
                @empty
                    <tr><td class="empty">Sem eventos para exibir.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

<section class="card mt-2">
    <div class="card__head"><h2>Próximos módulos</h2><span class="badge">Roadmap</span></div>
    <div class="card__body text-2">
        Agenda inteligente, fila e senhas, prontuário, receitas/atestados com impressão, financeiro, pagamentos (ASAAS/Cielo),
        convênios, portal do paciente e atendimento por IA/WhatsApp serão habilitados por fases. Os indicadores
        operacionais e financeiros deste painel aparecem conforme cada módulo for entregue — nada aqui é simulado.
    </div>
</section>
@endsection
