@extends('layouts.app')
@section('title', $pharmacy->trade_name)
@section('content')
<div class="top"><div><h1>{{ $pharmacy->trade_name }} @unless($pharmacy->active)<span class="badge b-cancelado">inativa</span>@endunless</h1>
  <div class="sub">{{ $pharmacy->legal_name }} · CNPJ {{ $pharmacy->cnpj }} · {{ $pharmacy->fullAddress() }} · <a href="{{ $pharmacy->mapsUrl() }}" target="_blank">mapa</a></div></div>
  <div class="row auto"><a class="btn" href="{{ route('reports', ['farmacias', 'farmacia' => $pharmacy->id]) }}">📊 Relatório</a><a class="btn p" href="{{ route('pharmacies.edit', $pharmacy) }}">Editar</a></div></div>
<div class="grid g4" style="margin-bottom:16px">
  <div class="kpi"><div class="l">Regra</div><div class="v">{{ number_format($pharmacy->commission_percent, 2, ',', '') }}%</div><div class="s">cobrança {{ $pharmacy->commission_cycle }}</div></div>
  <div class="kpi"><div class="l">Cotações no mês</div><div class="v">{{ $month['quotes_received'] ?? 0 }}</div><div class="s">respondeu {{ $month['response_rate'] ?? '—' }}% · {{ minutes_label($month['avg_response_min'] ?? null) }}</div></div>
  <div class="kpi"><div class="l">Vendas no mês</div><div class="v">{{ money($month['orders_total'] ?? 0) }}</div><div class="s">{{ $month['orders'] ?? 0 }} pedidos</div></div>
  <div class="kpi"><div class="l">Comissão no mês</div><div class="v">{{ money($month['commission'] ?? 0) }}</div><div class="s">base {{ money($month['commission_base'] ?? 0) }}</div></div>
</div>
<div class="grid g2">
  <div class="card"><h2>🛵 Zonas de entrega por bairro</h2>
    <p class="small muted">Padrão: {{ $pharmacy->delivers ? 'entrega até '.number_format($pharmacy->delivery_radius_km, 1, ',', '').' km, '.money($pharmacy->delivery_base_fee).' + '.money($pharmacy->delivery_fee_per_km).'/km'.($pharmacy->free_delivery_above ? ', grátis acima de '.money($pharmacy->free_delivery_above) : '') : 'não entrega' }}</p>
    <table><tr><th>Bairro</th><th class="num">Taxa</th><th class="num">Prazo</th><th></th></tr>
      @forelse($pharmacy->zones as $z)<tr><td>{{ $z->neighborhood->label() }}</td><td class="num">{{ money($z->fee) }}</td><td class="num">{{ $z->eta_min ? $z->eta_min.' min' : '—' }}</td>
        <td><form method="post" action="{{ route('pharmacies.zone.delete', [$pharmacy, $z]) }}">@csrf @method('DELETE')<button class="btn sm r">✕</button></form></td></tr>
      @empty<tr><td colspan="4" class="muted small">Nenhuma zona específica.</td></tr>@endforelse</table>
    <form method="post" action="{{ route('pharmacies.zone', $pharmacy) }}" class="row" style="margin-top:8px">@csrf
      <select name="neighborhood_id" required>@include('partials.neighborhood-options')</select><input name="fee" placeholder="Taxa R$"><input name="eta_min" type="number" placeholder="Prazo (min)"><button class="btn sm auto">Adicionar</button></form>
  </div>
  <div class="card"><h2>🔑 Acesso ao painel da farmácia</h2>
    @forelse($pharmacy->users as $u)<div class="small">{{ $u->name }} — {{ $u->email }} {{ $u->active ? '' : '(inativo)' }}</div>@empty<p class="muted small">Ninguém tem acesso ainda. A farmácia também responde pelo link enviado no WhatsApp.</p>@endforelse
    <form method="post" action="{{ route('pharmacies.user', $pharmacy) }}" style="margin-top:8px">@csrf
      <div class="row"><input name="name" placeholder="Nome" required><input name="email" type="email" placeholder="E-mail" required><input name="password" type="password" placeholder="Senha (mín. 8)" required><button class="btn sm auto">Criar acesso</button></div></form>
  </div>
</div>
<div class="card table-wrap"><h2>Fechamentos de comissão</h2><table>
  <tr><th>#</th><th>Período</th><th class="num">Base</th><th class="num">Comissão</th><th>Situação</th></tr>
  @forelse($statements as $s)<tr><td><a href="{{ route('commissions.show', $s) }}">#{{ $s->id }}</a></td><td>{{ $s->period_start->format('d/m/Y') }} a {{ $s->period_end->format('d/m/Y') }}</td><td class="num">{{ money($s->base_total) }}</td><td class="num">{{ money($s->commission_amount) }}</td><td><span class="badge b-{{ $s->status }}">{{ \App\Models\CommissionStatement::STATUSES[$s->status] }}</span></td></tr>
  @empty<tr><td colspan="5" class="empty">Nenhum fechamento.</td></tr>@endforelse
</table></div>
@endsection
