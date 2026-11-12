@extends('layouts.admin')
@section('title', 'Proprietário — visão geral')
@section('content')
<div class="stats">
  <div class="stat good"><label>Receita recorrente (MRR)</label><b>{{ brl($mrr) }}</b></div>
  <div class="stat"><label>Recebido no mês</label><b>{{ brl($received) }}</b></div>
  <div class="stat {{ $overdue > 0 ? 'bad' : '' }}"><label>Inadimplência</label><b>{{ brl($overdue) }}</b></div>
  <div class="stat"><label>Faturas vencidas</label><b>{{ $overdueCount }}</b></div>
</div>
<div class="stats">
  @foreach (\App\Models\Subscription::STATUS as $k => $l)<div class="stat {{ in_array($k,['past_due','suspended']) && ($counts[$k] ?? 0) ? 'bad' : '' }}"><label>{{ $l }}</label><b>{{ $counts[$k] ?? 0 }}</b></div>@endforeach
</div>
<div class="grid2">
  <div class="card"><h3>Clientes por plano</h3>
    <div class="table-wrap" style="border:0"><table class="t"><tbody>@forelse ($byPlan as $plan => $n)<tr><td>{{ $plan }}</td><td class="n">{{ $n }}</td></tr>@empty<tr><td class="muted">Nenhum cliente ativo.</td></tr>@endforelse</tbody></table></div></div>
  <div class="card"><h3>Uso no mês</h3>
    <p class="mt0 small">WhatsApp: {{ (int) $usage?->wa_in }} recebidas · {{ (int) $usage?->wa_out }} enviadas<br>IA: {{ (int) $usage?->ai }} chamadas · {{ number_format((int) $usage?->tin, 0, ',', '.') }} tokens de entrada · {{ number_format((int) $usage?->tout, 0, ',', '.') }} de saída</p>
    <a class="small" href="{{ route('admin.usage') }}">Detalhar por cliente</a></div>
</div>
<div class="grid2">
  <div class="card"><h3>Testes terminando em até 3 dias</h3>
    <ul class="small" style="margin:0;padding-left:18px">@forelse ($trialsEnding as $s)<li><a href="{{ route('admin.accounts.show', $s->account) }}">{{ $s->account->name }}</a> — {{ $s->trial_ends_at->format('d/m') }}</li>@empty<li class="muted">Nenhum.</li>@endforelse</ul></div>
  <div class="card"><h3>Faturamento (12 meses)</h3>
    <div class="table-wrap" style="border:0"><table class="t"><tbody>@forelse ($revenue as $m => $v)<tr><td>{{ $m }}</td><td class="n">{{ brl($v) }}</td></tr>@empty<tr><td class="muted">Sem pagamentos ainda.</td></tr>@endforelse</tbody></table></div></div>
</div>
<section class="block"><div class="blockhead"><h2>Clientes recentes</h2><a href="{{ route('admin.accounts.index') }}">ver todos</a></div>
<div class="table-wrap"><table class="t"><thead><tr><th>Cliente</th><th>Plano</th><th>Situação</th><th>Desde</th></tr></thead><tbody>
@foreach ($recent as $a)<tr><td><a href="{{ route('admin.accounts.show', $a) }}">{{ $a->name }}</a></td><td>{{ $a->subscription?->plan->name }}</td><td>{{ \App\Models\Subscription::STATUS[$a->subscription?->status] ?? '—' }}</td><td>{{ $a->created_at->format('d/m/Y') }}</td></tr>@endforeach
</tbody></table></div></section>
@endsection
