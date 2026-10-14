@extends('layouts.app', ['title' => 'Repasses · Melhor Sprint'])

@php
    use App\Core\Support\Format;
    use App\Modules\Sprint\Models\SprintPayment;
    $t = $totals;
@endphp

@section('content')
@include('sprint._head', ['subtitle' => 'Repasses por profissional, valores da clínica, comissão e NFS-e'])

<form method="get" class="row mb-2">
    <label class="sr-only" for="rf">De</label><input id="rf" type="date" name="from" value="{{ $from }}" class="input w-auto">
    <label class="sr-only" for="rt">Até</label><input id="rt" type="date" name="to" value="{{ $to }}" class="input w-auto">
    <button class="btn" type="submit">Filtrar</button>
    <a class="btn btn-ghost" href="{{ route('sprint.reports.export', ['from' => $from, 'to' => $to]) }}">Exportar CSV (Excel)</a>
</form>

<div class="grid grid-4">
    <div class="card kpi"><div class="kpi__label">Recebido (bruto)</div><div class="kpi__value">{{ Format::money($t['gross']) }}</div><div class="kpi__hint">pagamentos confirmados − estornos</div></div>
    <div class="card kpi ms-kpi-pro"><div class="kpi__label">Profissionais</div><div class="kpi__value">{{ Format::money($t['professional']) }}</div><div class="kpi__hint">{{ Format::money($t['professional_native']) }} direto pelo gateway · {{ Format::money($t['professional_pending']) }} a repassar</div></div>
    <div class="card kpi ms-kpi-house"><div class="kpi__label">Ficou na clínica</div><div class="kpi__value">{{ Format::money($t['house']) }}</div><div class="kpi__hint">líquido de tarifas ({{ Format::money($t['fee']) }}) e comissão</div></div>
    <div class="card kpi"><div class="kpi__label">Comissão Melhor Sprint</div><div class="kpi__value">{{ Format::money($t['platform']) }}</div><div class="kpi__hint">{{ Format::money($t['platform_native']) }} separada no gateway · NFS-e: {{ $t['nfse_count'] }} nota(s), {{ Format::money($t['nfse_cents']) }}</div></div>
</div>

<section class="card mt-2">
    <div class="card__head"><h2>Repasse por profissional</h2></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Profissional</th><th class="t-right">Vendas</th><th class="t-right">Recebido direto (gateway)</th><th class="t-right">A repassar</th><th class="t-right">Já repassado</th><th class="t-right">Total</th><th></th></tr></thead>
        <tbody>
        @forelse ($byProfessional as $proId => $rows)
            @php
                $native = (int) $rows->where('mode', 'native')->sum('total');
                $pending = (int) $rows->where('mode', 'internal')->where('status', 'pending')->sum('total');
                $settled = (int) $rows->where('mode', 'internal')->where('status', 'settled')->sum('total');
                $pro = $professionals[$proId] ?? null;
            @endphp
            <tr>
                <td><strong>{{ $pro?->name ?? '—' }}</strong>@if ($pro?->pix_key)<br><span class="small muted">PIX {{ $pro->pix_key }}</span>@endif</td>
                <td class="t-right small">{{ (int) $rows->max('sales') }}</td>
                <td class="t-right">{{ Format::money($native) }}</td>
                <td class="t-right"><strong>{{ Format::money($pending) }}</strong></td>
                <td class="t-right">{{ Format::money($settled) }}</td>
                <td class="t-right">{{ Format::money($native + $pending + $settled) }}</td>
                <td class="actions">
                    @if ($pro && $pending > 0)
                        <form method="post" action="{{ route('sprint.reports.settle', $pro) }}" data-confirm="Fechar o repasse de {{ $pro->name }} ({{ Format::money($pending) }}) e gerar a conta a pagar?">
                            @csrf <input type="hidden" name="from" value="{{ $from }}"><input type="hidden" name="to" value="{{ $to }}">
                            <button class="btn btn-sm btn-primary" type="submit">Fechar repasse</button></form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="empty">Nenhum repasse no período.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div class="card__body"><p class="help">"Recebido direto" = parte que o ASAAS/Cielo já depositou na conta do profissional (split nativo). "A repassar" = parte recebida pela clínica (dinheiro, maquininha sem split ou profissional sem carteira); ao fechar, vira conta a pagar no financeiro.</p></div>
</section>

<section class="card mt-2">
    <div class="card__head"><h2>Valores que ficaram na clínica por forma de pagamento</h2></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Forma</th><th>Canal</th><th class="t-right">Pagamentos</th><th class="t-right">Ficou na clínica</th></tr></thead>
        <tbody>
        @forelse ($methods as $m)
            <tr><td>{{ SprintPayment::METHODS[$m->method] ?? $m->method }}</td><td class="small">{{ SprintPayment::CHANNELS[$m->channel] ?? $m->channel }}</td><td class="t-right">{{ (int) $m->qty }}</td><td class="t-right">{{ Format::money((int) $m->house) }}</td></tr>
        @empty
            <tr><td colspan="4" class="empty">Sem pagamentos no período.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>
@endsection
