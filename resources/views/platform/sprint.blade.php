@extends('layouts.app', ['title' => 'Melhor Sprint — comissões'])

@php
    use App\Core\Support\Format;
    $byCompany = $rows->keyBy('company_id');
@endphp

@push('head')
<link rel="stylesheet" href="{{ asset('assets/css/sprint.css') }}?v={{ filemtime(public_path('assets/css/sprint.css')) }}">
@endpush

@section('content')
<div class="ms-hero">
    <div class="ms-hero__brand">
        <img src="{{ asset('assets/img/melhor-sprint-mark.webp') }}" alt="" width="150" height="52">
        <div><h1>Melhor Sprint — comissões</h1><p>Comissão da plataforma sobre as vendas das clínicas. Taxa padrão: <strong>{{ number_format($defaultBp / 100, 2, ',', '.') }}%</strong>
            · split nativo: ASAAS {{ $wallet ? '✓ carteira configurada' : '— sem carteira (SPRINT_PLATFORM_ASAAS_WALLET)' }} · Cielo {{ $subordinate ? '✓ subordinado configurado' : '— sem subordinado' }}</p></div>
    </div>
    <form method="get" class="row">
        <label class="sr-only" for="pf">De</label><input id="pf" type="date" name="from" value="{{ $from }}" class="input w-auto">
        <label class="sr-only" for="pt">Até</label><input id="pt" type="date" name="to" value="{{ $to }}" class="input w-auto">
        <button class="btn" type="submit">Filtrar</button>
    </form>
</div>

<div class="grid grid-4 mb-2">
    <div class="card kpi"><div class="kpi__label">Volume vendido</div><div class="kpi__value">{{ Format::money((int) $volume->sum()) }}</div><div class="kpi__hint">pagamentos confirmados</div></div>
    <div class="card kpi"><div class="kpi__label">Comissão separada no gateway</div><div class="kpi__value kpi-pos">{{ Format::money((int) $rows->sum('native_cents')) }}</div><div class="kpi__hint">já na conta da plataforma</div></div>
    <div class="card kpi"><div class="kpi__label">A cobrar das clínicas</div><div class="kpi__value">{{ Format::money((int) $rows->sum('to_invoice_cents')) }}</div><div class="kpi__hint">dinheiro, maquininha, sem carteira</div></div>
    <div class="card kpi"><div class="kpi__label">Já cobrado</div><div class="kpi__value">{{ Format::money((int) $rows->sum('invoiced_cents')) }}</div><div class="kpi__hint">no período</div></div>
</div>

<section class="card">
    <div class="card__head"><h2>Clínicas</h2></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Clínica</th><th class="t-right">Vendido</th><th class="t-right">Separada no gateway</th><th class="t-right">A cobrar</th><th class="t-right">Cobrada</th><th>Taxa</th><th></th></tr></thead>
        <tbody>
        @foreach ($companies as $c)
            @php $r = $byCompany[$c->id] ?? null; $custom = $c->sprint_commission_bp; @endphp
            <tr>
                <td><strong>{{ $c->trade_name }}</strong></td>
                <td class="t-right">{{ Format::money((int) ($volume[$c->id] ?? 0)) }}</td>
                <td class="t-right">{{ Format::money((int) ($r->native_cents ?? 0)) }}</td>
                <td class="t-right"><strong>{{ Format::money((int) ($r->to_invoice_cents ?? 0)) }}</strong></td>
                <td class="t-right">{{ Format::money((int) ($r->invoiced_cents ?? 0)) }}</td>
                <td>
                    <form method="post" action="{{ route('platform.sprint.rate', $c) }}" class="row">@csrf @method('put')
                        <label class="sr-only" for="rate-{{ $c->id }}">Taxa (%)</label>
                        <input id="rate-{{ $c->id }}" name="rate" class="input input-sm w-auto" size="6" value="{{ $custom !== null ? number_format($custom / 100, 2, ',', '.') : '' }}" placeholder="{{ number_format($defaultBp / 100, 2, ',', '.') }}">
                        <button class="btn btn-sm" type="submit">Salvar</button></form>
                </td>
                <td class="actions">
                    @if (($r->to_invoice_cents ?? 0) > 0)
                        <form method="post" action="{{ route('platform.sprint.invoice', $c) }}" data-confirm="Marcar {{ Format::money((int) $r->to_invoice_cents) }} como cobrado de {{ $c->trade_name }}?">
                            @csrf <input type="hidden" name="from" value="{{ $from }}"><input type="hidden" name="to" value="{{ $to }}">
                            <button class="btn btn-sm btn-primary" type="submit">Marcar como cobrada</button></form>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    <div class="card__body"><p class="help">Taxa em branco = taxa padrão (SPRINT_COMMISSION_BP). Mudança vale para as próximas vendas. Com a carteira ASAAS / subordinado Cielo da plataforma configurados, a comissão é separada pelo próprio gateway em cada PIX/cartão online; o restante fica "a cobrar".</p></div>
</section>
@endsection
