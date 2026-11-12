@extends('layouts.admin')
@section('title', 'Faturamento')
@section('content')
<div class="stats">@foreach (\App\Models\Invoice::STATUS as $k => $l)@if($k!=='refunded')<div class="stat {{ $k==='overdue' && isset($totals[$k]) ? 'bad' : '' }}"><label>{{ $l }} ({{ $totals[$k]->qtd ?? 0 }})</label><b>{{ brl($totals[$k]->total ?? 0) }}</b></div>@endif @endforeach</div>
<form class="tools" method="get"><select name="status" data-autosubmit style="border:1px solid var(--line);border-radius:10px;padding:9px 12px;background:var(--surface)"><option value="">Todas</option>@foreach (\App\Models\Invoice::STATUS as $k => $l)<option value="{{ $k }}" @selected(request('status')===$k)>{{ $l }}</option>@endforeach</select></form>
<div class="table-wrap"><table class="t"><thead><tr><th>Cliente</th><th>Vencimento</th><th class="n">Valor</th><th>Situação</th><th>Forma</th><th>Pago em</th></tr></thead><tbody>
@forelse ($list as $i)<tr><td><a href="{{ route('admin.accounts.show', $i->account) }}">{{ $i->account->name }}</a></td><td>{{ $i->due_date->format('d/m/Y') }}</td><td class="n">{{ brl($i->amount_cents) }}</td>
  <td><span class="pill {{ $i->status==='paid'?'ok':($i->status==='overdue'?'bad':'warn') }}">{{ \App\Models\Invoice::STATUS[$i->status] ?? $i->status }}</span></td><td>{{ $i->billing_type }}</td><td>{{ $i->paid_at?->format('d/m/Y') }}</td></tr>
@empty<tr><td colspan="6" class="muted">Nenhuma fatura.</td></tr>@endforelse
</tbody></table></div>
{{ $list->links('partials.pager') }}
@endsection
