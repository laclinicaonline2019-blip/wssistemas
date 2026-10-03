@extends('layouts.app', ['title' => 'Assinaturas e faturas'])

@php use App\Core\Support\Format; use App\Modules\Billing\Models\Subscription; @endphp

@section('content')
<div class="page-head">
    <div><h1>Assinaturas e faturas</h1><p>Cobrança das clínicas pela plataforma — gateway: <strong>{{ $provider === 'asaas' ? 'ASAAS ('.(config('billing.asaas.sandbox') ? 'SANDBOX' : 'PRODUÇÃO').')' : 'MOCK (nada é cobrado)' }}</strong>.</p></div>
    <form method="get" class="row"><select name="status" class="input w-auto" aria-label="Situação"><option value="">Todas</option>@foreach (Subscription::STATUSES as $k => $l)<option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach</select><button class="btn" type="submit">Filtrar</button></form>
</div>
<div class="grid grid-4 mb-2">
    <div class="card kpi"><div class="kpi__label">MRR (receita recorrente mensal)</div><div class="kpi__value">{{ Format::money($mrr) }}</div><div class="kpi__hint">ativas + em atraso</div></div>
    @foreach (['trialing' => 'Em teste', 'past_due' => 'Em atraso', 'suspended' => 'Bloqueadas'] as $k => $l)
        <div class="card kpi"><div class="kpi__label">{{ $l }}</div><div class="kpi__value">{{ (int) ($counts[$k] ?? 0) }}</div><div class="kpi__hint">&nbsp;</div></div>
    @endforeach
</div>
<div class="grid grid-2">
    <section class="card">
        <div class="card__head"><h2>Clínicas</h2></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Clínica</th><th>Plano</th><th>Situação</th><th>Pago/teste até</th><th></th></tr></thead>
            <tbody>
            @foreach ($subs as $s)
                <tr><td><strong>{{ $s->company?->trade_name }}</strong></td><td class="small">{{ $s->plan?->name ?? '—' }} {{ $s->plan ? '· '.mb_strtolower(Subscription::CYCLES[$s->cycle]) : '' }}</td>
                    <td><span class="badge {{ ['active' => 'badge-success', 'trialing' => 'badge-info', 'past_due' => 'badge-warning', 'suspended' => 'badge-danger'][$s->status] ?? '' }}">{{ $s->statusLabel() }}</span>@if ($s->cancel_at_period_end)<div class="small text-danger">cancela no fim</div>@endif</td>
                    <td class="nowrap">{{ $s->paidUntil()?->format('d/m/Y') ?? '—' }}</td>
                    <td class="actions"><a class="btn btn-sm" href="{{ route('platform.billing.company', $s->company_id) }}">Abrir</a></td></tr>
            @endforeach
            </tbody>
        </table></div>
    </section>
    <section class="card">
        <div class="card__head"><h2>Faturas vencidas</h2></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Clínica</th><th>Vencimento</th><th class="num">Valor</th></tr></thead>
            <tbody>@forelse ($overdue as $i)<tr><td><a href="{{ route('platform.billing.company', $i->company_id) }}">{{ $i->company?->trade_name }}</a></td><td>{{ $i->due_date->format('d/m/Y') }}</td><td class="num">{{ Format::money($i->amount_cents) }}</td></tr>@empty<tr><td colspan="3" class="empty">Nenhuma.</td></tr>@endforelse</tbody>
        </table></div>
    </section>
</div>
@endsection
