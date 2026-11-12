@extends('layouts.admin')
@section('title', $a->name)
@section('content')
<div class="blockhead"><h2>{{ $a->name }}</h2><span class="small muted">{{ $a->email }} · {{ $a->person_type === 'pf' ? 'Pessoa física' : 'Pessoa jurídica' }} · cliente desde {{ $a->created_at->format('d/m/Y') }}</span></div>
<div class="stats">
  <div class="stat"><label>Plano</label><b>{{ $a->subscription?->plan->name }}</b></div>
  <div class="stat {{ in_array($a->subscription?->status, ['active','trialing']) ? 'good' : 'bad' }}"><label>Assinatura</label><b>{{ \App\Models\Subscription::STATUS[$a->subscription?->status] ?? '—' }}</b></div>
  <div class="stat"><label>Valor</label><b>{{ brl($a->subscription?->price_cents) }}</b></div>
  <div class="stat {{ $a->status !== 'active' ? 'bad' : '' }}"><label>Conta</label><b>{{ ['active'=>'Ativa','suspended'=>'Suspensa','canceled'=>'Cancelada'][$a->status] ?? $a->status }}</b></div>
</div>
@if ($a->purge_after)<div class="banner warn">Exclusão definitiva dos dados (LGPD) agendada para {{ $a->purge_after->format('d/m/Y') }}.</div>@endif
<div class="grid2">
  <form class="card" method="post" action="{{ route('admin.accounts.action', $a) }}">@csrf
    <h3>Ações</h3>
    <div class="field"><label>Ação</label><select name="action">
      <option value="extend_trial">Prorrogar teste grátis</option><option value="change_plan">Trocar plano</option>
      <option value="suspend">Suspender (bloqueia novos lançamentos)</option><option value="reactivate">Reativar</option><option value="cancel">Cancelar agora</option></select></div>
    <div class="form">
      <div class="field"><label>Dias (prorrogação)</label><input type="number" name="days" min="1" max="365" value="7"></div>
      <div class="field"><label>Plano (troca)</label><select name="plan">@foreach ($plans as $p)<option value="{{ $p->code }}" @selected($a->subscription?->plan_id===$p->id)>{{ $p->name }}</option>@endforeach</select></div>
      <div class="field"><label>Ciclo</label><select name="cycle"><option value="monthly">Mensal</option><option value="yearly" @selected($a->subscription?->cycle==='yearly')>Anual</option></select></div>
      <div class="field"><label>Motivo</label><input name="reason" maxlength="200"></div>
    </div>
    <button class="btn primary" type="submit">Executar</button>
  </form>
  <div class="card"><h3>Empresas e usuários</h3>
    <p class="small mt0"><b>Empresas:</b> {{ $companies->pluck('name')->implode(', ') ?: '—' }}</p>
    <ul class="small" style="margin:0;padding-left:18px">@foreach ($members as $m)<li>{{ $m->user->name }} — {{ \App\Models\Membership::ROLES[$m->role] }} {{ $m->status !== 'active' ? '(inativo)' : '' }}</li>@endforeach</ul>
    <h3>Uso (últimos meses)</h3>
    <ul class="small" style="margin:0;padding-left:18px">@forelse ($usage as $u)<li>{{ $u->period }}: WhatsApp {{ $u->whatsapp_in }}/{{ $u->whatsapp_out }} · IA {{ $u->ai_calls }}</li>@empty<li class="muted">Sem uso.</li>@endforelse</ul>
    <p class="small muted">Por privacidade, o painel do proprietário não mostra os lançamentos financeiros do cliente.</p></div>
</div>
<section class="block"><h2>Faturas</h2>
<div class="table-wrap"><table class="t"><thead><tr><th>Vencimento</th><th class="n">Valor</th><th>Situação</th><th>Forma</th><th>Pago em</th><th></th></tr></thead><tbody>
@forelse ($invoices as $i)<tr><td>{{ $i->due_date->format('d/m/Y') }}</td><td class="n">{{ brl($i->amount_cents) }}</td><td>{{ \App\Models\Invoice::STATUS[$i->status] ?? $i->status }}</td><td>{{ $i->billing_type }}</td><td>{{ $i->paid_at?->format('d/m/Y') }} <span class="small muted">{{ $i->paid_note }}</span></td>
  <td>@if(in_array($i->status,['pending','overdue']))<form method="post" action="{{ route('admin.invoices.paid', $i) }}" class="tools">@csrf<input name="note" required placeholder="Motivo da baixa manual" style="border:1px solid var(--line);border-radius:8px;padding:5px 8px"><button class="btn sm" type="submit">Dar baixa</button></form>@endif</td></tr>
@empty<tr><td colspan="6" class="muted">Sem faturas.</td></tr>@endforelse
</tbody></table></div></section>
<section class="block"><h2>Histórico da assinatura</h2>
<div class="table-wrap"><table class="t"><thead><tr><th>Quando</th><th>Evento</th><th>De → para</th><th>Detalhes</th></tr></thead><tbody>
@foreach ($events as $e)<tr><td class="small">{{ $e->created_at->format('d/m/Y H:i') }}</td><td>{{ str_replace('_',' ',$e->event) }}</td><td class="small">{{ $e->from_status }} → {{ $e->to_status }}</td><td class="small muted">{{ $e->data ? json_encode($e->data, JSON_UNESCAPED_UNICODE) : '' }}</td></tr>@endforeach
</tbody></table></div></section>
@endsection
