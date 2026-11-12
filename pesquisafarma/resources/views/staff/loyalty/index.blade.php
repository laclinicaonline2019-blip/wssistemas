@extends('layouts.app')
@section('title', 'Pontos e brindes')
@section('content')
<div class="top"><div><h1>Programa de pontos, brindes e premiação</h1><div class="sub">Quanto mais o cliente compra pela Pesquisa Farma (nota fiscal conferida), mais pontos ganha e troca por brindes.</div></div></div>
<div class="grid g2">
  <div class="card">
    <h2>Regras de pontuação</h2>
    <table>
      <tr><th>Regra</th><th>Tipo</th><th>Detalhe</th><th></th></tr>
      @forelse($rules as $r)
      <tr style="{{ $r->active ? '' : 'opacity:.5' }}"><td>{{ $r->name }}</td><td class="small">{{ \App\Models\LoyaltyRule::TYPES[$r->type] }}</td>
        <td class="small">@if($r->type==='por_valor'){{ $r->points_per_real }} pt/R$ @if($r->min_purchase>0) (compra ≥ {{ money($r->min_purchase) }})@endif
          @elseif($r->type==='por_produto')+{{ $r->bonus_points }} pts se tiver "{{ $r->keyword }}"
          @else x{{ $r->multiplier }} em {{ $pharmacies->firstWhere('id', $r->pharmacy_id)?->trade_name }}@endif
          @if($r->valid_until) · até {{ $r->valid_until->format('d/m/Y') }}@endif</td>
        <td><form method="post" action="{{ route('loyalty.rule.toggle', $r) }}">@csrf<button class="btn sm">{{ $r->active ? 'Desativar' : 'Ativar' }}</button></form></td></tr>
      @empty <tr><td colspan="4" class="muted small">Sem regras: vale o padrão das configurações ({{ \App\Models\Setting::get('points_per_real') }} ponto por R$ 1,00).</td></tr>@endforelse
    </table>
    <details style="margin-top:10px"><summary>+ Nova regra</summary>
      <form method="post" action="{{ route('loyalty.rule') }}" style="margin-top:8px">@csrf
        <div class="row"><div class="f"><label>Nome</label><input name="name" required></div><div class="f"><label>Tipo</label><select name="type">@foreach(\App\Models\LoyaltyRule::TYPES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div></div>
        <div class="row"><div class="f"><label>Pontos por R$ (por valor)</label><input name="points_per_real" type="number" step="0.01"></div><div class="f"><label>Compra mínima (R$)</label><input name="min_purchase" type="number" step="0.01"></div></div>
        <div class="row"><div class="f"><label>Palavra do produto (por produto)</label><input name="keyword" placeholder="ex.: vitamina"></div><div class="f"><label>Bônus (pts)</label><input name="bonus_points" type="number"></div></div>
        <div class="row"><div class="f"><label>Farmácia (multiplicador)</label><select name="pharmacy_id"><option value="">—</option>@foreach($pharmacies as $p)<option value="{{ $p->id }}">{{ $p->trade_name }}</option>@endforeach</select></div><div class="f"><label>Multiplicador</label><input name="multiplier" type="number" step="0.1" min="1"></div><div class="f"><label>Válida até</label><input type="date" name="valid_until"></div></div>
        <button class="btn p">Criar regra</button>
      </form>
    </details>
  </div>
  <div class="card">
    <h2>Brindes</h2>
    <table><tr><th>Brinde</th><th class="num">Pontos</th><th class="num">Estoque</th><th class="num">Mín. compras</th><th></th></tr>
      @forelse($rewards as $rw)<tr style="{{ $rw->active ? '' : 'opacity:.5' }}"><td>{{ $rw->name }}<div class="small muted">{{ $rw->description }}</div></td><td class="num">{{ $rw->points_cost }}</td><td class="num">{{ $rw->stock ?? '∞' }}</td><td class="num">{{ $rw->min_purchases }}</td>
        <td><form method="post" action="{{ route('loyalty.reward.toggle', $rw) }}">@csrf<button class="btn sm">{{ $rw->active ? 'Desativar' : 'Ativar' }}</button></form></td></tr>
      @empty<tr><td colspan="5" class="empty">Nenhum brinde.</td></tr>@endforelse</table>
    <details style="margin-top:10px"><summary>+ Novo brinde</summary>
      <form method="post" action="{{ route('loyalty.reward') }}" style="margin-top:8px">@csrf
        <div class="row"><div class="f"><label>Nome</label><input name="name" required></div><div class="f"><label>Pontos</label><input name="points_cost" type="number" min="1" required></div></div>
        <div class="row"><div class="f"><label>Estoque (vazio = ilimitado)</label><input name="stock" type="number"></div><div class="f"><label>Nº mínimo de compras</label><input name="min_purchases" type="number" value="0"></div></div>
        <div class="f"><label>Descrição</label><input name="description"></div><button class="btn p">Cadastrar</button>
      </form>
    </details>
  </div>
</div>
<div class="card table-wrap"><h2>Trocas de pontos</h2><table>
  <tr><th>Data</th><th>Cliente</th><th>Brinde</th><th class="num">Pontos</th><th>Situação</th><th></th></tr>
  @forelse($redemptions as $rd)
  <tr><td class="small">{{ $rd->created_at->format('d/m/Y H:i') }}</td><td><a href="{{ route('customers.show', $rd->customer) }}">{{ $rd->customer->displayName() }}</a></td><td>{{ $rd->reward->name }}</td><td class="num">{{ $rd->points }}</td>
    <td><span class="badge b-{{ $rd->status }}">{{ \App\Models\Redemption::STATUSES[$rd->status] }}</span></td>
    <td>@if($rd->status === 'solicitado')<form method="post" action="{{ route('loyalty.redemption', $rd) }}" style="display:inline">@csrf<input type="hidden" name="status" value="entregue"><button class="btn sm g">Entregue</button></form>
      <form method="post" action="{{ route('loyalty.redemption', $rd) }}" style="display:inline">@csrf<input type="hidden" name="status" value="cancelado"><button class="btn sm r">Cancelar (estorna)</button></form>@endif</td></tr>
  @empty <tr><td colspan="6" class="empty">Nenhuma troca.</td></tr>@endforelse
</table></div>
<div class="grid g2">
  <div class="card">
    <h2>🏆 Premiação do mês</h2>
    <p class="small muted">Participam os clientes que enviaram nota fiscal conferida no mês{{ \App\Models\Setting::get('prize_requires_quote') === '1' ? ' de compras feitas pela cotação' : '' }}. Sorteio: cada nota = 1 chance. Ranking: maior valor comprado.</p>
    <table><tr><th>Mês</th><th>Premiação</th><th>Regra</th><th>Vencedor</th><th></th></tr>
      @forelse($campaigns as $cp)<tr><td>{{ $cp->month }}</td><td>{{ $cp->title }}</td><td>{{ $cp->rule }}@if($cp->min_total > 0) (≥ {{ money($cp->min_total) }})@endif</td><td>{{ $cp->winner?->displayName() ?? '—' }}</td>
        <td>@unless($cp->drawn_at)<form method="post" action="{{ route('loyalty.draw', $cp) }}">@csrf<button class="btn sm p" data-confirm="Apurar o vencedor agora?">Apurar</button></form>@endunless</td></tr>
      @empty<tr><td colspan="5" class="empty">Nenhuma premiação.</td></tr>@endforelse</table>
    <details style="margin-top:10px"><summary>+ Nova premiação</summary>
      <form method="post" action="{{ route('loyalty.campaign') }}" style="margin-top:8px">@csrf
        <div class="row"><div class="f"><label>Mês</label><input type="month" name="month" value="{{ now()->format('Y-m') }}" required></div><div class="f"><label>Título</label><input name="title" required placeholder="Ex.: Kit beleza de novembro"></div></div>
        <div class="row"><div class="f"><label>Regra</label><select name="rule"><option value="sorteio">Sorteio</option><option value="ranking">Ranking (quem mais comprou)</option></select></div><div class="f"><label>Compra mínima no mês (R$)</label><input type="number" step="0.01" name="min_total"></div></div>
        <div class="f"><label>Descrição</label><input name="description"></div><button class="btn p">Salvar</button>
      </form>
    </details>
  </div>
  <div class="card">
    <h2>Participantes de <form style="display:inline"><input type="month" name="mes" value="{{ $month }}" onchange="this.form.submit()" style="width:auto"></form></h2>
    <table><tr><th>Cliente</th><th class="num">Notas</th><th class="num">Total</th></tr>
      @forelse($participants as $p)<tr><td>{{ $p['customer']->displayName() }}</td><td class="num">{{ $p['receipts'] }}</td><td class="num">{{ money($p['total']) }}</td></tr>
      @empty<tr><td colspan="3" class="empty">Nenhum participante ainda.</td></tr>@endforelse</table>
    <h3 style="margin-top:12px">Maiores saldos de pontos</h3>
    @foreach($topPoints as $c)<div class="small">{{ $c->displayName() }} — <b>{{ $c->points_balance }}</b> pts</div>@endforeach
  </div>
</div>
@endsection
