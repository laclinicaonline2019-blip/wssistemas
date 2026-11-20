@extends('layouts.app')
@section('title', 'Relatórios automáticos')
@section('content')
<div class="blockhead"><h2>Relatórios automáticos</h2><span class="muted small">Um relatório para cada CPF/CNPJ — a saúde de cada entidade, sem misturar.</span></div>
<form class="card" method="post" action="{{ route('report_prefs.update') }}">@csrf @method('PUT')
  <div class="form">
    <div class="checks full"><b class="small">Quando</b>
      <label><input type="hidden" name="weekly" value="0"><input type="checkbox" name="weekly" value="1" @checked($pref->weekly)> Semanal — toda segunda-feira, com a semana anterior</label>
      <label><input type="hidden" name="monthly" value="0"><input type="checkbox" name="monthly" value="1" @checked($pref->monthly)> Mensal — todo dia 1º, com o mês anterior</label></div>
    <div class="checks full"><b class="small">Por onde</b>
      <label><input type="hidden" name="via_whatsapp" value="0"><input type="checkbox" name="via_whatsapp" value="1" @checked($pref->via_whatsapp) @disabled(! $plan?->has('whatsapp'))> WhatsApp</label>
      <label><input type="hidden" name="via_email" value="0"><input type="checkbox" name="via_email" value="1" @checked($pref->via_email)> E-mail</label></div>
    <div class="field"><label>Número que recebe</label><select name="whatsapp_contact_id"><option value="">Número validado do administrador{{ $defaultContact ? ' (final '.$defaultContact->phone_last4.')' : '' }}</option>
      @foreach ($contacts as $c)<option value="{{ $c->id }}" @selected($pref->whatsapp_contact_id === $c->id)>{{ $c->label }} (final {{ $c->phone_last4 }})</option>@endforeach</select></div>
    <div class="field"><label>E-mail que recebe <span class="muted">(vazio = do administrador)</span></label><input name="email" type="email" value="{{ $pref->email }}"></div>
    <label class="inline-check full"><input type="hidden" name="all_companies" value="0"><input type="checkbox" name="all_companies" value="1" @checked(! is_array($pref->company_ids))> Todas as entidades</label>
    <div class="checks full"><span class="small muted">Ou só estas:</span>@foreach ($companies as $c)<label><input type="checkbox" name="companies[]" value="{{ $c->id }}" @checked(is_array($pref->company_ids) && in_array($c->id, $pref->company_ids, true))> {{ $c->name }}</label>@endforeach</div>
  </div>
  <p class="small muted">WhatsApp: se a pessoa conversou com o assistente nas últimas 24 horas, o relatório completo vai direto. Fora desse prazo, a Meta só permite mensagem com <b>modelo aprovado</b> (cobrado pela Meta por conversa)
    {{ $templateOk ? '— modelo configurado: o aviso convida a responder RELATÓRIO para receber o detalhe.' : '— nenhum modelo configurado ainda, então fora da janela o WhatsApp não é enviado (o e-mail, se marcado, segue normalmente).' }}
    A qualquer momento, mande <b>relatório</b> ou <b>relatório mensal</b> no WhatsApp.</p>
  <div class="formacts"><button class="btn primary" type="submit">Salvar</button></div>
</form>
@if ($sample)
<div class="card"><h3 class="mt0">Exemplo (semana passada — {{ $companies->first()->name }})</h3><pre class="small" style="white-space:pre-wrap;font-family:inherit;margin:0">{{ str_replace('*', '', $sample) }}</pre></div>
@endif
@if ($runs->isNotEmpty())
<div class="table-wrap"><table class="t"><thead><tr><th>Envio</th><th>Período</th><th>Canal</th><th>Situação</th></tr></thead><tbody>
@foreach ($runs as $r)<tr><td>{{ $r->created_at->format('d/m/Y H:i') }}</td><td>{{ $r->kind }} {{ $r->period_key }}</td><td>{{ $r->channel }}</td><td>{{ $r->status }} <span class="small muted">{{ $r->detail }}</span></td></tr>@endforeach
</tbody></table></div>
@endif
@endsection
