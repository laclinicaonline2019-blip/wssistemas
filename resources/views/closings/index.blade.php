@extends('layouts.app', ['title' => 'Fechamento médico × clínica'])

@php use App\Core\Support\Format; @endphp

@section('content')
<div class="page-head">
    <div><h1>Fechamento mensal médico × clínica</h1><p>Demonstrativo do mês por médico: atendimentos, recebimentos, convênios e repasse. Depois de fechado, vira um retrato imutável que o médico confirma ou contesta.</p></div>
    <form method="get" class="row"><label class="sr-only" for="cp">Mês</label><input id="cp" type="month" name="period" value="{{ $period }}" class="input w-auto"><button class="btn" type="submit">Ver</button></form>
</div>
<section class="card">
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Médico</th><th>Situação</th><th class="num">Parte do médico</th><th class="num">Repasse da clínica</th><th></th></tr></thead>
        <tbody>
        @forelse ($doctors as $d)
            @php $c = $closings[$d->id] ?? null; @endphp
            <tr><td><strong>{{ $d->displayName() }}</strong> <span class="small muted">CRM {{ $d->crm }}/{{ $d->crm_state }}</span></td>
                <td>@if ($c)<span class="badge {{ ['confirmed' => 'badge-success', 'disputed' => 'badge-danger'][$c->status] ?? 'badge-warning' }}">{{ $c->statusLabel() }}</span> <span class="small muted">v{{ $c->version }}</span>@else<span class="badge">aberto</span>@endif</td>
                <td class="num">{{ $c ? Format::money($c->doctor_share_cents) : '—' }}</td><td class="num">{{ $c ? Format::money($c->to_pay_cents) : '—' }}</td>
                <td class="actions">@if ($c)<a class="btn btn-sm" href="{{ route('closings.show', $c) }}">Demonstrativo</a>@endif
                    @if (! $c || $c->status === 'disputed')<a class="btn btn-sm {{ $c ? 'btn-primary' : '' }}" href="{{ route('closings.preview', [$d, 'period' => $period]) }}">{{ $c ? 'Refazer' : 'Conferir e fechar' }}</a>@endif</td></tr>
        @empty
            <tr><td colspan="5" class="empty">Nenhum médico.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>
@endsection
