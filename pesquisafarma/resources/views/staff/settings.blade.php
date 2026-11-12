@extends('layouts.app')
@section('title', 'Configurações')
@section('content')
<div class="top"><h1>Configurações</h1></div>
<form method="post" action="{{ route('settings.update') }}">@csrf
<div class="grid g2">
  <div class="card"><h2>Empresa e atendimento</h2>
    <div class="row"><div class="f"><label>Nome</label><input name="company_name" value="{{ $s['company_name'] }}"></div><div class="f"><label>WhatsApp da empresa</label><input name="company_whatsapp" value="{{ $s['company_whatsapp'] }}"></div></div>
    <div class="f"><label>Mensagem de boas-vindas (primeiro contato)</label><textarea name="welcome_message" rows="3">{{ $s['welcome_message'] }}</textarea></div>
    <div class="row"><div class="f"><label>Raio "mais próximas" (km)</label><input type="number" step="0.5" name="radius_near_km" value="{{ $s['radius_near_km'] }}"></div>
      <div class="f"><label>Raio "ampliada" (km)</label><input type="number" step="0.5" name="radius_far_km" value="{{ $s['radius_far_km'] }}"></div></div>
    <div class="row"><div class="f"><label>Prazo de resposta das farmácias (min)</label><input type="number" name="response_minutes" value="{{ $s['response_minutes'] }}"></div>
      <div class="f"><label>Máx. farmácias pré-selecionadas</label><input type="number" name="max_pharmacies" value="{{ $s['max_pharmacies'] }}"></div></div>
    <div class="f"><label>Pós-venda: enviar a pesquisa após (min) — retirada e cotação sem resposta</label><input type="number" name="followup_minutes" value="{{ $s['followup_minutes'] }}">
      <div class="small muted">Na entrega, a pesquisa vai no prazo informado pela farmácia + 30 min. Envio só entre 8h e 21h.</div></div>
    <label class="check"><input type="checkbox" name="auto_dispatch" value="1" @checked($s['auto_dispatch'] === '1')> Envio automático: quando a IA identificar os itens, enviar direto às farmácias próximas</label>
  </div>
  <div class="card"><h2>Cliente e fidelidade</h2>
    <label class="check"><input type="checkbox" name="portal_enabled" value="1" @checked($s['portal_enabled'] === '1')> Área do cliente disponível (<a href="{{ route('portal.login') }}" target="_blank">{{ route('portal.login') }}</a>)</label>
    <label class="check"><input type="checkbox" name="loyalty_enabled" value="1" @checked($s['loyalty_enabled'] === '1')> Programa de pontos ativo</label>
    <div class="f" style="margin-top:8px"><label>Pontos por R$ 1,00 (padrão, sem regras)</label><input type="number" step="0.01" name="points_per_real" value="{{ $s['points_per_real'] }}"></div>
    <div class="row"><div class="f"><label>Multiplicador — compra pela cotação</label><input type="number" step="0.1" name="points_quote_multiplier" value="{{ $s['points_quote_multiplier'] }}"></div>
      <div class="f"><label>Multiplicador — compra direta, sem cotação</label><input type="number" step="0.1" name="points_direct_multiplier" value="{{ $s['points_direct_multiplier'] }}"></div></div>
    <label class="check"><input type="checkbox" name="prize_requires_quote" value="1" @checked($s['prize_requires_quote'] === '1')> Premiação do mês só para compras feitas pela cotação</label>
    <p class="small muted">Incentivo para o cliente sempre cotar com a Pesquisa Farma. A comissão da farmácia é cobrada nos dois casos.</p>
    <h2 style="margin-top:16px">Inteligência artificial (Claude)</h2>
    <label class="check"><input type="checkbox" name="ai_enabled" value="1" @checked($s['ai_enabled'] === '1')> Usar IA para ler pedidos, receitas e notas fiscais</label>
    <p class="small">{{ $aiConfigured ? ($aiOn ? '✅ IA ativa (modelo '.config('pesquisafarma.anthropic.model').').' : 'Chave configurada, IA desligada.') : '⚠️ Defina ANTHROPIC_API_KEY no .env. Sem chave, os itens de texto são separados por regras simples.' }}</p>
    <h2 style="margin-top:16px">WhatsApp</h2>
    <p class="small">Provedor: <b>{{ strtoupper($driver) }}</b> (WHATSAPP_DRIVER no .env: mock, zapi, evolution ou meta).</p>
    <div class="f"><label>URL do webhook (configure no provedor)</label><input id="wh" value="{{ $webhook }}" readonly></div><a href="#" class="btn sm" data-copy="#wh">Copiar</a>
  </div>
</div>
<button class="btn p lg">Salvar configurações</button>
</form>
@endsection
