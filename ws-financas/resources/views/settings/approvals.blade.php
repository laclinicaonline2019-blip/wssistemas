@extends('layouts.app')
@section('title', 'Aprovações')
@section('content')
<div class="blockhead"><h2>Despesas aguardando aprovação</h2><span class="muted small">Pendentes não entram no saldo, nos relatórios nem nas metas até serem aprovadas.</span></div>
<div class="table-wrap"><table class="t"><thead><tr><th>Despesa</th><th>Valor</th><th>Data</th>@if($multi)<th>Empresa</th>@endif<th>Centro de custo</th><th>Lançada por</th><th></th></tr></thead><tbody>
@forelse ($list as $e)
  <tr><td><b>{{ $e->description }}</b><br><span class="small muted">{{ $e->category }}{{ $e->installments_total ? ' · '.($e->recurrence_kind === 'parcelada' ? $e->installments_total.'x' : 'repete '.$e->installments_total.' meses') : '' }} · {{ $e->source === 'whatsapp' ? 'WhatsApp' : 'painel' }}</span></td>
    <td class="num">{{ brl($e->amount_cents) }}</td><td>{{ $e->due_date->format('d/m/Y') }}</td>@if($multi)<td>{{ $e->company->name }}</td>@endif
    <td>{{ $e->costCenter?->name ?? '—' }}</td><td>{{ $e->responsible ?? '—' }}</td>
    <td class="nowrap">
      <form method="post" action="{{ route('approvals.decide', $e->id) }}" class="inline">@csrf<input type="hidden" name="decisao" value="aprovar"><button class="btn sm primary" type="submit">Aprovar</button></form>
      <form method="post" action="{{ route('approvals.decide', $e->id) }}" class="inline" data-confirm="Rejeitar esta despesa? Ela não entrará no saldo e ficará registrada na auditoria.">@csrf<input type="hidden" name="decisao" value="rejeitar"><button class="btn sm" type="submit">Rejeitar</button></form>
    </td></tr>
@empty
  <tr><td colspan="7" class="muted">Nenhuma despesa aguardando aprovação. 🎉</td></tr>
@endforelse
</tbody></table></div>
<p class="small muted">Pelo WhatsApp: mande <b>PENDENTES</b> e responda <b>APROVAR 1</b>, <b>REJEITAR 1</b> ou <b>APROVAR TODOS</b>.</p>
@endsection
