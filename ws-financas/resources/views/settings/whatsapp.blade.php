@extends('layouts.app')
@section('title', 'WhatsApp')
@section('content')
<div class="blockhead"><h2>Assistente no WhatsApp</h2>
  <span class="muted small">Uso no mês: {{ number_format($usage->whatsapp_in, 0, ',', '.') }} de {{ $plan?->whatsapp_messages_month ? number_format($plan->whatsapp_messages_month, 0, ',', '.') : '—' }} mensagens</span></div>
@if (! $plan?->has('whatsapp'))
  <div class="banner warn"><span>Seu plano não inclui o assistente no WhatsApp.</span>@if(can_do('assinatura.gerenciar'))<a class="btn sm" href="{{ route('billing.show') }}">Fazer upgrade</a>@endif</div>
@endif
<div class="card small">
  <p class="mt0">Somente os números abaixo conseguem lançar ou consultar pelo WhatsApp oficial do WS Finanças{{ config('services.whatsapp.display_number') ? ' ('.config('services.whatsapp.display_number').')' : '' }}.
  Um número ligado a um usuário segue as permissões dele (por exemplo, o contador só consulta as empresas autorizadas). Lembretes automáticos só são enviados a quem marcar a opção (regra da Meta).</p>
</div>
<div class="table-wrap"><table class="t"><thead><tr><th>Número</th><th>Configuração</th></tr></thead><tbody>
@forelse ($list as $c)
  <tr><td><b>{{ $c->label }}</b><br><span class="small muted">final {{ $c->phone_last4 }}</span> @unless($c->active)<span class="pill bad">inativo</span>@endunless</td>
  <td><details class="edit"><summary>Empresa padrão: {{ $c->defaultCompany?->name ?? 'perguntar' }} · {{ $c->can_write ? 'lança e consulta' : 'só consulta' }}{{ $c->user ? ' · usuário '.$c->user->name : '' }}</summary>
    <form method="post" action="{{ route('whatsapp.update', $c) }}" class="form" style="margin-top:8px">@csrf @method('PUT')
      <div class="field"><label>Identificação</label><input name="label" required value="{{ $c->label }}"></div>
      <div class="field"><label>Empresa padrão</label><select name="default_company_id"><option value="">Perguntar quando houver mais de uma</option>@foreach ($allCompanies as $co)<option value="{{ $co->id }}" @selected($c->default_company_id===$co->id)>{{ $co->name }}</option>@endforeach</select></div>
      <div class="field"><label>Usuário vinculado</label><select name="user_id"><option value="">Nenhum</option>@foreach ($members as $m)<option value="{{ $m->user_id }}" @selected($c->user_id===$m->user_id)>{{ $m->user->name }} ({{ \App\Models\Membership::ROLES[$m->role] }})</option>@endforeach</select></div>
      <div class="checks full"><label><input type="hidden" name="can_write" value="0"><input type="checkbox" name="can_write" value="1" @checked($c->can_write)> Pode lançar</label>
        <label><input type="hidden" name="reminders_opt_in" value="0"><input type="checkbox" name="reminders_opt_in" value="1" @checked($c->reminders_opt_in)> Aceita lembretes</label>
        <label><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked($c->active)> Ativo</label></div>
      <div class="formacts full"><button class="btn primary" type="submit">Salvar</button></div>
    </form>
    <form method="post" action="{{ route('whatsapp.destroy', $c) }}" data-confirm="Remover este número?">@csrf @method('DELETE')<button class="icon" type="submit">remover número</button></form>
  </details></td></tr>
@empty
  <tr><td colspan="2" class="muted">Nenhum número autorizado ainda.</td></tr>
@endforelse
</tbody></table></div>
@if ($plan?->has('whatsapp'))
<form class="card" method="post" action="{{ route('whatsapp.store') }}">@csrf
  <h2>Autorizar número</h2>
  <div class="form">
    <div class="field"><label for="wp">Celular com DDD</label><input id="wp" name="phone" required inputmode="tel" placeholder="(11) 98765-4321" value="{{ old('phone') }}"></div>
    <div class="field"><label for="wl">Identificação</label><input id="wl" name="label" required placeholder="Ex.: Celular do Carlos" value="{{ old('label') }}"></div>
    <div class="field"><label for="wc">Empresa padrão</label><select id="wc" name="default_company_id"><option value="">Perguntar quando houver mais de uma</option>@foreach ($allCompanies as $co)<option value="{{ $co->id }}">{{ $co->name }}</option>@endforeach</select></div>
    <div class="field"><label for="wu">Usuário vinculado</label><select id="wu" name="user_id"><option value="">Nenhum</option>@foreach ($members as $m)<option value="{{ $m->user_id }}">{{ $m->user->name }}</option>@endforeach</select></div>
    <div class="checks full"><label><input type="hidden" name="can_write" value="0"><input type="checkbox" name="can_write" value="1" checked> Pode lançar</label>
      <label><input type="hidden" name="reminders_opt_in" value="0"><input type="checkbox" name="reminders_opt_in" value="1"> Aceita receber lembretes de contas</label></div>
  </div>
  <div class="formacts"><button class="btn primary" type="submit">Autorizar</button></div>
</form>
@endif
@endsection
