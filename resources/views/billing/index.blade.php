@extends('layouts.app', ['title' => 'Assinatura'])

@php
    use App\Core\Support\Format;
    use App\Modules\Billing\Models\Subscription;
    $labels = ['max_users' => 'Usuários', 'max_branches' => 'Filiais', 'max_doctors' => 'Médicos'];
@endphp

@section('content')
<div class="page-head">
    <div><h1>Assinatura da aivexaclinica</h1><p>Plano, faturas e forma de pagamento da sua clínica.@if ($mock) <span class="badge badge-warning">MOCK — homologação, nada é cobrado</span>@endif</p></div>
</div>
@if ($locked)<div class="alert alert-error">O acesso da equipe está bloqueado. Pague a fatura em aberto (ou escolha um plano) para liberar na hora.</div>@endif

<div class="grid grid-3 mb-2">
    <section class="card">
        <div class="card__head"><h2>Situação</h2><span class="badge {{ ['active' => 'badge-success', 'trialing' => 'badge-info', 'past_due' => 'badge-warning', 'suspended' => 'badge-danger'][$s->status] ?? '' }}">{{ $s->statusLabel() }}</span></div>
        <div class="card__body stack small">
            <div><strong>Plano:</strong> {{ $s->plan?->name ?? 'nenhum plano escolhido' }} @if ($s->plan)({{ mb_strtolower(Subscription::CYCLES[$s->cycle]) }} — {{ Format::money(Subscription::priceOf($s->plan, $s->cycle)) }})@endif</div>
            @if ($s->status === 'trialing')<div><strong>Teste grátis até:</strong> {{ $s->trial_ends_on?->format('d/m/Y') ?? '—' }}</div>
            @elseif ($s->current_period_end)<div><strong>Período pago:</strong> {{ $s->current_period_start?->format('d/m/Y') }} a {{ $s->current_period_end->copy()->subDay()->format('d/m/Y') }}</div>@endif
            @if ($s->pendingPlan)<div class="text-info"><strong>Agendado:</strong> {{ $s->pendingPlan->name }} ({{ mb_strtolower(Subscription::CYCLES[$s->pending_cycle ?? $s->cycle]) }}) a partir da próxima renovação.</div>@endif
            @if ($s->cancel_at_period_end)
                <div class="text-danger"><strong>Cancelamento agendado</strong> para {{ $s->paidUntil()?->format('d/m/Y') }}.</div>
                <form method="post" action="{{ route('billing.resume') }}">@csrf<button class="btn btn-sm" type="submit">Desfazer cancelamento</button></form>
            @endif
        </div>
    </section>
    <section class="card">
        <div class="card__head"><h2>Uso do plano</h2></div>
        <div class="card__body stack small">
            @foreach ($usage as $k => $u)
                <div class="spread"><span>{{ $labels[$k] ?? $k }}</span><span>{{ $u['used'] }} / {{ $u['limit'] ?? 'ilimitado' }}</span></div>
            @endforeach
        </div>
    </section>
    <section class="card">
        <div class="card__head"><h2>Faturas em aberto</h2></div>
        <div class="card__body stack small">
            @forelse ($invoices->where('status', 'open') as $inv)
                <div><strong>{{ Format::money($inv->amount_cents) }}</strong> · vence {{ $inv->due_date->format('d/m/Y') }} <span class="badge {{ $inv->isOverdue() ? 'badge-danger' : 'badge-warning' }}">{{ $inv->statusLabel() }}</span>
                    <div class="row mt-1"><a class="btn btn-sm btn-primary" href="{{ route('billing.pay', $inv) }}" target="_blank" rel="noopener">Pagar (PIX, boleto ou cartão)</a>
                        <form method="post" action="{{ route('billing.check', $inv) }}">@csrf<button class="btn btn-sm" type="submit">Já paguei</button></form></div>
                    @if ($inv->gateway_error)<div class="text-danger">{{ $inv->gateway_error }}</div>@endif</div>
            @empty
                <p class="muted">Nenhuma fatura em aberto.</p>
            @endforelse
        </div>
    </section>
</div>

<section class="card mb-2">
    <div class="card__head"><h2>Planos</h2><span class="help">Upgrade vale na hora (cobra só a diferença dos dias restantes). Downgrade e troca de ciclo valem na próxima renovação.</span></div>
    <div class="card__body grid grid-3">
        @foreach ($plans as $p)
            <form method="post" action="{{ route('billing.plan') }}" class="plan-card {{ $s->saas_plan_id === $p->id ? 'is-current' : '' }}">@csrf
                <input type="hidden" name="plan_id" value="{{ $p->id }}">
                <h3>{{ $p->name }} @if ($s->saas_plan_id === $p->id)<span class="badge badge-success">atual</span>@endif</h3>
                <p class="small muted">{{ $p->description }}</p>
                <p><strong>{{ Format::money($p->price_monthly_cents) }}</strong>/mês @if ($p->price_yearly_cents)<span class="small muted">ou {{ Format::money($p->price_yearly_cents) }}/ano</span>@endif</p>
                <ul class="list small">
                    @foreach ($labels as $k => $l)<li>{{ $l }}: {{ $p->limit($k) ?? 'ilimitado' }}</li>@endforeach
                </ul>
                <div class="row"><label class="sr-only" for="cy-{{ $p->id }}">Ciclo</label>
                    <select id="cy-{{ $p->id }}" name="cycle" class="input w-auto">@foreach (Subscription::CYCLES as $k => $l)<option value="{{ $k }}" @selected($k === $s->cycle)>{{ $l }}</option>@endforeach</select>
                    <button class="btn btn-sm {{ $s->saas_plan_id === $p->id ? '' : 'btn-primary' }}" type="submit">{{ $s->saas_plan_id === $p->id ? 'Trocar ciclo' : 'Escolher' }}</button></div>
            </form>
        @endforeach
    </div>
</section>

<div class="grid grid-2">
    <section class="card">
        <div class="card__head"><h2>Histórico de faturas</h2></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Fatura</th><th>Descrição</th><th>Vencimento</th><th class="num">Valor</th><th>Situação</th></tr></thead>
            <tbody>
            @forelse ($invoices as $inv)
                <tr><td class="mono small">{{ $inv->number }}</td><td class="small">{{ $inv->description }}</td><td class="nowrap">{{ $inv->due_date->format('d/m/Y') }}</td>
                    <td class="num">{{ Format::money($inv->amount_cents) }}</td>
                    <td><span class="badge {{ $inv->status === 'paid' ? 'badge-success' : ($inv->isOverdue() ? 'badge-danger' : '') }}">{{ $inv->statusLabel() }}</span>@if ($inv->paid_at)<div class="small muted">{{ $inv->paid_at->timezone('America/Sao_Paulo')->format('d/m/Y') }}</div>@endif</td></tr>
            @empty
                <tr><td colspan="5" class="empty">Nenhuma fatura ainda.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
    @unless ($s->cancel_at_period_end || $s->status === 'cancelled')
        <section class="card">
            <div class="card__head"><h2>Cancelar assinatura</h2></div>
            <form method="post" action="{{ route('billing.cancel') }}" class="card__body stack" data-confirm="Cancelar a assinatura no fim do período pago?">@csrf
                <p class="small">O cancelamento vale no fim do período já pago ({{ $s->paidUntil()?->format('d/m/Y') ?? '—' }}). Os dados da clínica e os prontuários <strong>não são apagados</strong> (guarda legal de 20 anos); você pode pedir a exportação.</p>
                <label for="cr">Motivo</label><input id="cr" name="reason" class="input" required minlength="3" maxlength="255">
                <button class="btn" type="submit">Cancelar no fim do período</button></form>
        </section>
    @endunless
</div>
@endsection
