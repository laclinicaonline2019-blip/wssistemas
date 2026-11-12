@extends('layouts.admin')
@section('title', 'Clientes')
@section('content')
<form class="tools" method="get"><input class="field" name="busca" value="{{ request('busca') }}" placeholder="Buscar nome ou e-mail" style="border:1px solid var(--line);border-radius:10px;padding:9px 12px;background:var(--surface)">
  <select name="status" style="border:1px solid var(--line);border-radius:10px;padding:9px 12px;background:var(--surface)"><option value="">Todas as situações</option>@foreach (\App\Models\Subscription::STATUS as $k => $l)<option value="{{ $k }}" @selected(request('status')===$k)>{{ $l }}</option>@endforeach</select>
  <button class="btn" type="submit">Filtrar</button></form>
<div class="table-wrap"><table class="t"><thead><tr><th>Cliente</th><th>Plano</th><th>Situação</th><th class="n">Empresas</th><th class="n">Usuários</th><th>Vencimento</th></tr></thead><tbody>
@forelse ($list as $a)
  <tr><td><a href="{{ route('admin.accounts.show', $a) }}"><b>{{ $a->name }}</b></a><br><span class="small muted">{{ $a->email }}</span></td><td>{{ $a->subscription?->plan->name }} <span class="small muted">{{ $a->subscription?->cycle === 'yearly' ? 'anual' : 'mensal' }}</span></td>
    <td><span class="pill {{ in_array($a->subscription?->status, ['active','trialing']) ? 'ok' : 'bad' }}">{{ \App\Models\Subscription::STATUS[$a->subscription?->status] ?? '—' }}</span></td>
    <td class="n">{{ $a->companies_count }}/{{ $a->subscription?->plan->max_companies }}</td><td class="n">{{ $a->memberships_count }}/{{ $a->subscription?->plan->max_users }}</td>
    <td class="small">{{ $a->subscription?->status === 'trialing' ? 'teste até '.$a->subscription->trial_ends_at?->format('d/m/Y') : $a->subscription?->current_period_end?->format('d/m/Y') }}</td></tr>
@empty <tr><td colspan="6" class="muted">Nenhum cliente.</td></tr>
@endforelse
</tbody></table></div>
{{ $list->links('partials.pager') }}
<form class="card" method="post" action="{{ route('admin.accounts.store') }}">@csrf
  <h2>Cadastrar cliente</h2>
  <div class="form">
    <div class="field"><label>Nome / razão social</label><input name="name" required></div>
    <div class="field"><label>Tipo</label><select name="person_type"><option value="pj">Pessoa jurídica</option><option value="pf">Pessoa física</option></select></div>
    <div class="field"><label>CPF/CNPJ</label><input name="document"></div>
    <div class="field"><label>E-mail do administrador</label><input name="email" type="email" required></div>
    <div class="field"><label>Nome do administrador</label><input name="admin_name" required></div>
    <div class="field"><label>Plano</label><select name="plan">@foreach ($plans as $p)<option value="{{ $p->code }}">{{ $p->name }}</option>@endforeach</select></div>
    <div class="field"><label>Ciclo</label><select name="cycle"><option value="monthly">Mensal</option><option value="yearly">Anual</option></select></div>
    <div class="field"><label>Dias de teste grátis</label><input name="trial_days" type="number" min="0" max="365" value="{{ config('wsf.billing.trial_days') }}"></div>
  </div>
  <div class="formacts"><button class="btn primary" type="submit">Cadastrar e enviar convite</button></div>
</form>
@endsection
