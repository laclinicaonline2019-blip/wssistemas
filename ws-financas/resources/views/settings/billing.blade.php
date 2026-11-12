@extends('layouts.app')
@section('title', 'Assinatura')
@section('content')
<div class="blockhead"><h2>Assinatura</h2>@if($mock)<span class="pill warn">cobrança simulada (homologação)</span>@endif</div>
<div class="stats">
  <div class="stat"><label>Plano</label><b>{{ $sub?->plan->name ?? '—' }}</b></div>
  <div class="stat {{ in_array($sub?->status, ['past_due','suspended','canceled']) ? 'bad' : 'good' }}"><label>Situação</label><b>{{ \App\Models\Subscription::STATUS[$sub?->status] ?? '—' }}</b></div>
  <div class="stat"><label>Valor</label><b>{{ $sub ? brl($sub->price_cents) : '—' }}<span class="small muted">/{{ $sub?->cycle === 'yearly' ? 'ano' : 'mês' }}</span></b></div>
  <div class="stat"><label>{{ $sub?->status === 'trialing' ? 'Teste até' : 'Pago até' }}</label><b>{{ $sub?->status === 'trialing' ? $sub->trial_ends_at?->format('d/m/Y') : ($sub?->current_period_end?->format('d/m/Y') ?? '—') }}</b></div>
</div>
@if ($sub?->cancel_at_period_end)<div class="banner warn">Cancelamento agendado para {{ $sub->current_period_end?->format('d/m/Y') }}. Para desfazer, assine novamente abaixo.</div>@endif
<section class="block"><h2>{{ $sub?->gateway_subscription_id ? 'Trocar de plano' : 'Escolher plano e assinar' }}</h2>
<div class="plans">
@foreach ($plans as $p)
  <form class="plan {{ $sub?->plan_id === $p->id ? 'featured' : '' }}" method="post" action="{{ route('billing.subscribe') }}">@csrf
    <input type="hidden" name="plan" value="{{ $p->code }}">
    <h3>{{ $p->name }} @if($sub?->plan_id === $p->id)<span class="pill ok">atual</span>@endif</h3>
    <span class="price num">{{ brl($p->price_monthly_cents) }}<span class="small muted">/mês</span></span>
    <span class="small muted">ou {{ brl($p->price_yearly_cents) }}/ano</span>
    <ul><li>{{ $p->max_companies }} empresa(s) · {{ $p->max_users }} usuário(s)</li>@foreach (\App\Models\Plan::FEATURES as $k => $l) @if($p->has($k))<li>{{ $l }}</li>@endif @endforeach</ul>
    <div class="field"><label>Cobrança</label><select name="cycle"><option value="monthly" @selected($sub?->cycle !== 'yearly')>Mensal</option><option value="yearly" @selected($sub?->cycle === 'yearly')>Anual</option></select></div>
    @if (! $account->document)<div class="field"><label>CPF/CNPJ do titular</label><input name="document" required></div>@endif
    <button class="btn {{ $sub?->plan_id === $p->id ? '' : 'primary' }}" type="submit">{{ $sub?->gateway_subscription_id ? ($sub->plan_id === $p->id ? 'Atualizar ciclo' : 'Mudar para '.$p->name) : 'Assinar '.$p->name }}</button>
  </form>
@endforeach
</div>
<p class="small muted">Pagamento por Pix, boleto ou cartão na página segura do Asaas. A confirmação chega automaticamente e libera o acesso na hora. Durante o teste, a primeira cobrança vence no fim do período grátis.</p>
</section>
<section class="block"><h2>Cobranças</h2>
<div class="table-wrap"><table class="t"><thead><tr><th>Vencimento</th><th>Descrição</th><th class="n">Valor</th><th>Situação</th><th></th></tr></thead><tbody>
@forelse ($invoices as $i)
  <tr><td>{{ $i->due_date->format('d/m/Y') }}</td><td>{{ $i->description }}</td><td class="n">{{ brl($i->amount_cents) }}</td>
    <td><span class="pill {{ $i->status === 'paid' ? 'ok' : ($i->status === 'overdue' ? 'bad' : 'warn') }}">{{ \App\Models\Invoice::STATUS[$i->status] ?? $i->status }}</span></td>
    <td class="right">@if($i->invoice_url && in_array($i->status,['pending','overdue']))<a class="btn sm primary" href="{{ $i->invoice_url }}" target="_blank" rel="noopener">Pagar</a>@endif
      @if($mock && in_array($i->status,['pending','overdue']) && ! app()->isProduction())<form class="inline" method="post" action="{{ route('billing.simulate', $i) }}">@csrf<button class="btn sm" type="submit">Simular pagamento</button></form>@endif</td></tr>
@empty
  <tr><td colspan="5" class="muted">Nenhuma cobrança ainda.</td></tr>
@endforelse
</tbody></table></div></section>
<div class="grid2">
  <div class="card"><h3>Uso deste mês</h3>
    <p class="mt0 small">WhatsApp: {{ $usage->whatsapp_in }} recebidas / {{ $usage->whatsapp_out }} enviadas (limite {{ $sub?->plan->whatsapp_messages_month ?: '—' }})<br>Inteligência artificial: {{ $usage->ai_calls }} usos (limite {{ $sub?->plan->ai_calls_month ?: '—' }})</p></div>
  <div class="card"><h3>Histórico</h3>
    <ul class="small" style="margin:0;padding-left:18px">@foreach ($events as $e)<li>{{ $e->created_at->format('d/m/Y H:i') }} — {{ str_replace('_',' ',$e->event) }}</li>@endforeach</ul></div>
</div>
@if ($sub && ! in_array($sub->status, ['canceled']) && ! $sub->cancel_at_period_end)
<details class="card"><summary><b>Cancelar assinatura</b></summary>
  <form method="post" action="{{ route('billing.cancel') }}" class="form" style="margin-top:10px">@csrf
    <p class="full small mt0">O cancelamento vale ao fim do período pago. Depois disso seus dados ficam disponíveis para consulta e exportação por {{ config('wsf.lgpd.retention_days_after_cancel') }} dias e então são eliminados.</p>
    <div class="field"><label>Digite CANCELAR</label><input name="confirm" required></div>
    <div class="field"><label>Sua senha</label><input type="password" name="password" required></div>
    <div class="formacts full"><button class="btn danger" type="submit">Confirmar cancelamento</button></div>
  </form></details>
@endif
@endsection
