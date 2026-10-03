@php use App\Core\Support\Format; $a = $data['appointments']; $s = $data['split']; $i = $data['insurance']; @endphp
<div class="grid grid-4 mb-2">
    <div class="card kpi"><div class="kpi__label">Atendidos</div><div class="kpi__value">{{ $a['attended'] }}</div><div class="kpi__hint">{{ $a['private'] }} particular · {{ $a['insurance'] }} convênio</div></div>
    <div class="card kpi"><div class="kpi__label">Faltas / cancelados</div><div class="kpi__value">{{ $a['no_show'] }} / {{ $a['cancelled'] }}</div><div class="kpi__hint">{{ $a['total'] }} agendamentos</div></div>
    <div class="card kpi"><div class="kpi__label">Parte do médico</div><div class="kpi__value">{{ Format::money($s['share']) }}</div><div class="kpi__hint">sobre {{ Format::money($s['base']) }}</div></div>
    <div class="card kpi"><div class="kpi__label">Repasse a pagar pela clínica</div><div class="kpi__value">{{ Format::money($s['internal_pending']) }}</div><div class="kpi__hint">{{ Format::money($s['native']) }} já recebido direto · {{ Format::money($s['internal_settled']) }} já repassado</div></div>
</div>
<div class="grid grid-2">
    <section class="card">
        <div class="card__head"><h2>Recebimentos das contas do médico</h2><span class="small muted">{{ $data['receipts']['count'] }} · {{ Format::money($data['receipts']['total']) }}</span></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Data</th><th>Descrição</th><th>Forma</th><th class="num">Valor</th></tr></thead>
            <tbody>@forelse ($data['receipts']['lines'] as $l)<tr><td class="nowrap">{{ $l['date'] }}</td><td>{{ $l['description'] }} <span class="small muted">{{ $l['payer'] }}</span></td><td class="small">{{ $l['method'] }}</td><td class="num nowrap">{{ Format::money($l['amount']) }}</td></tr>@empty<tr><td colspan="4" class="empty">Sem recebimentos.</td></tr>@endforelse</tbody>
        </table></div>
    </section>
    <section class="card">
        <div class="card__head"><h2>Repasse (split)</h2></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Data</th><th>Origem</th><th class="num">Base</th><th class="num">Médico</th></tr></thead>
            <tbody>@forelse ($s['lines'] as $l)<tr><td class="nowrap">{{ $l['date'] }}</td><td class="small">{{ $l['mode'] }}@if ($l['status'] === 'settled') · repassado @elseif ($l['status'] === 'reversed') · estornado @endif</td><td class="num">{{ Format::money($l['base']) }}</td><td class="num">{{ Format::money($l['amount']) }}</td></tr>@empty<tr><td colspan="4" class="empty">Sem repasse no mês (verifique as regras em Financeiro → Repasses).</td></tr>@endforelse</tbody>
        </table></div>
        <div class="card__body small">Convênios (atendimentos do mês): {{ $i['guides'] }} guia(s) · apresentado {{ Format::money($i['total']) }} · pago {{ Format::money($i['paid']) }} · glosado {{ Format::money($i['glosa']) }}</div>
    </section>
</div>
