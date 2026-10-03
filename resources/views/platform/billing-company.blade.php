@extends('layouts.app', ['title' => 'Assinatura — '.$company->trade_name])

@php use App\Core\Support\Format; use App\Modules\Billing\Models\Subscription; @endphp

@section('content')
<div class="page-head">
    <div><h1>{{ $company->trade_name }}</h1><p><span class="badge">{{ $s->statusLabel() }}</span> {{ $s->plan?->name ?? 'sem plano' }} · {{ $s->status === 'trialing' ? 'teste até '.($s->trial_ends_on?->format('d/m/Y') ?? '—') : 'pago até '.($s->paidUntil()?->copy()->subDay()->format('d/m/Y') ?? '—') }}</p></div>
    <div class="row"><a class="btn" href="{{ route('platform.companies.show', $company) }}">Empresa</a><a class="btn" href="{{ route('platform.billing.index') }}">Assinaturas</a></div>
</div>
<div class="grid grid-2 mb-2">
    <section class="card">
        <div class="card__head"><h2>Plano</h2></div>
        <form method="post" action="{{ route('platform.billing.plan', $company) }}" class="card__body form-grid">@csrf
            <div class="field col-6"><label for="pp">Plano</label><select id="pp" name="plan_id" class="input">@foreach ($plans as $p)<option value="{{ $p->id }}" @selected($s->saas_plan_id === $p->id)>{{ $p->name }} — {{ Format::money($p->price_monthly_cents) }}/mês</option>@endforeach</select></div>
            <div class="field col-6"><label for="pc">Ciclo</label><select id="pc" name="cycle" class="input">@foreach (Subscription::CYCLES as $k => $l)<option value="{{ $k }}" @selected($s->cycle === $k)>{{ $l }}</option>@endforeach</select></div>
            <div class="col-12"><button class="btn" type="submit">Aplicar (mesmas regras de upgrade/downgrade)</button></div>
        </form>
        @if ($s->status === 'trialing')
            <form method="post" action="{{ route('platform.billing.trial', $company) }}" class="card__body row">@csrf
                <label for="td">Prorrogar teste (dias)</label><input id="td" name="days" type="number" min="1" max="90" value="7" class="input w-auto"><button class="btn btn-sm" type="submit">Prorrogar</button></form>
        @endif
    </section>
    <section class="card">
        <div class="card__head"><h2>Faturas</h2></div>
        <div class="card__body stack small">
            @forelse ($invoices as $inv)
                <div class="stack">
                    <div><span class="mono">{{ $inv->number }}</span> · {{ Format::money($inv->amount_cents) }} · vence {{ $inv->due_date->format('d/m/Y') }} · <span class="badge {{ $inv->status === 'paid' ? 'badge-success' : ($inv->isOverdue() ? 'badge-danger' : '') }}">{{ $inv->statusLabel() }}</span>
                        @if ($inv->paid_via)<span class="muted">({{ $inv->paid_via === 'manual' ? 'manual' : 'gateway' }})</span>@endif</div>
                    <div class="muted">{{ $inv->description }} @if ($inv->notes)· {{ $inv->notes }}@endif</div>
                    @if ($inv->status === 'open')
                        <div class="row">
                            <form method="post" action="{{ route('platform.billing.manual', $inv) }}" class="row">@csrf<input name="amount" class="input w-auto" value="{{ number_format($inv->amount_cents / 100, 2, ',', '') }}" aria-label="Valor recebido"><input name="notes" class="input w-auto" placeholder="Comprovante (ex.: TED 05/10)" required aria-label="Comprovante"><button class="btn btn-sm" type="submit">Baixa manual</button></form>
                            <form method="post" action="{{ route('platform.billing.void', $inv) }}" class="row">@csrf<input name="reason" class="input w-auto" placeholder="Motivo" required aria-label="Motivo"><button class="btn btn-sm btn-ghost" type="submit">Cancelar</button></form>
                        </div>
                    @endif
                </div>
            @empty
                <p class="muted">Nenhuma fatura.</p>
            @endforelse
        </div>
    </section>
</div>
@endsection
