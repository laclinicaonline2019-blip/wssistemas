@extends('layouts.app', ['title' => 'Plataforma'])

@section('content')
<div class="page-head">
    <div><h1>Visão geral da plataforma</h1><p>Indicadores agregados — sem acesso a dados clínicos das empresas.</p></div>
    <a class="btn btn-primary" href="{{ route('platform.companies.create') }}"><svg><use href="#i-plus"/></svg>Nova clínica</a>
</div>

<div class="grid grid-4">
    <div class="card kpi"><div class="kpi__label">Clínicas ativas</div><div class="kpi__value">{{ $metrics['companies']['active'] }}</div><div class="kpi__hint">{{ $metrics['companies']['trial'] }} em trial</div></div>
    <div class="card kpi"><div class="kpi__label">Inativas</div><div class="kpi__value">{{ $metrics['companies']['suspended'] + $metrics['companies']['cancelled'] }}</div><div class="kpi__hint">{{ $metrics['companies']['suspended'] }} suspensas · {{ $metrics['companies']['cancelled'] }} canceladas</div></div>
    <div class="card kpi"><div class="kpi__label">Receita recorrente (MRR)</div><div class="kpi__value">R$ {{ number_format($metrics['mrr_cents'] / 100, 2, ',', '.') }}</div><div class="kpi__hint">Base: planos das clínicas ativas</div></div>
    <div class="card kpi"><div class="kpi__label">Usuários das clínicas</div><div class="kpi__value">{{ $metrics['users'] }}</div><div class="kpi__hint">{{ $metrics['branches'] }} unidades</div></div>
</div>

<div class="grid grid-2 mt-2">
    <section class="card">
        <div class="card__head"><h2>Saúde do sistema</h2>
            @if ($healthy)<span class="badge badge-success">Operacional</span>@else<span class="badge badge-danger">Degradado</span>@endif</div>
        <div class="table-wrap"><table class="table"><tbody>
            @foreach ($checks as $name => $c)
                <tr><td><strong>{{ $name }}</strong><div class="small muted">{{ $c['detail'] }}</div></td>
                    <td class="small muted nowrap">{{ $c['ms'] }} ms</td>
                    <td>@if ($c['ok'])<span class="badge badge-success">OK</span>@else<span class="badge badge-danger">Falha</span>@endif</td></tr>
            @endforeach
            <tr><td><strong>Falhas de login (24h)</strong></td><td></td><td>{{ $metrics['failed_logins_24h'] }}</td></tr>
            <tr><td><strong>Jobs com falha</strong></td><td></td><td>{{ $metrics['failed_jobs'] }}</td></tr>
        </tbody></table></div>
    </section>
    <section class="card">
        <div class="card__head"><h2>Clínicas recentes</h2><a class="btn btn-sm" href="{{ route('platform.companies.index') }}">Todas</a></div>
        <div class="table-wrap"><table class="table"><tbody>
            @forelse ($recentCompanies as $c)
                <tr><td><a href="{{ route('platform.companies.show', $c) }}"><strong>{{ $c->trade_name }}</strong></a><div class="small muted">{{ $c->plan?->name ?? 'sem plano' }}</div></td>
                    <td>@include('platform._status', ['status' => $c->status])</td></tr>
            @empty
                <tr><td class="empty">Nenhuma clínica cadastrada.</td></tr>
            @endforelse
        </tbody></table></div>
    </section>
</div>
@endsection
