@extends('layouts.app')
@section('title', 'Comissões')
@section('content')
<div class="top"><div><h1>Comissões das farmácias</h1><div class="sub">Fechamentos semanais ou mensais conforme a regra de cada farmácia. Gerados automaticamente todo dia às 06:10 para o período anterior.</div></div></div>
<div class="card table-wrap"><h2>Em aberto (ainda não fechado)</h2><table>
  <tr><th>Farmácia</th><th>Regra</th><th class="num">Base em aberto</th><th class="num">Comissão em aberto</th><th>Gerar fechamento</th></tr>
  @foreach($pending as $p)
  <tr><td><b>{{ $p['pharmacy']->trade_name }}</b></td><td>{{ number_format($p['pharmacy']->commission_percent, 2, ',', '') }}% · {{ $p['pharmacy']->commission_cycle }}</td>
    <td class="num">{{ money($p['open_base']) }}</td><td class="num"><b>{{ money($p['open_amount']) }}</b></td>
    <td><form method="post" action="{{ route('commissions.generate') }}" class="row" style="gap:4px;flex-wrap:nowrap">@csrf<input type="hidden" name="pharmacy_id" value="{{ $p['pharmacy']->id }}">
      <input type="date" name="period_start" value="{{ $p['next_start']->toDateString() }}"><input type="date" name="period_end" value="{{ $p['next_end']->toDateString() }}"><button class="btn sm p auto">Fechar</button></form></td></tr>
  @endforeach
</table></div>
<div class="card table-wrap"><h2>Fechamentos</h2><table>
  <tr><th>#</th><th>Farmácia</th><th>Período</th><th class="num">Vendas</th><th class="num">Base</th><th class="num">Comissão</th><th>Situação</th></tr>
  @forelse($statements as $s)
  <tr><td><a href="{{ route('commissions.show', $s) }}">#{{ $s->id }}</a></td><td>{{ $s->pharmacy->trade_name }}</td><td>{{ $s->period_start->format('d/m/Y') }} a {{ $s->period_end->format('d/m/Y') }} <span class="small muted">({{ $s->cycle }})</span></td>
    <td class="num">{{ $s->sales_count }}</td><td class="num">{{ money($s->base_total) }}</td><td class="num"><b>{{ money($s->commission_amount) }}</b></td><td><span class="badge b-{{ $s->status }}">{{ \App\Models\CommissionStatement::STATUSES[$s->status] }}</span></td></tr>
  @empty <tr><td colspan="7" class="empty">Nenhum fechamento ainda.</td></tr>@endforelse
</table>{{ $statements->links() }}</div>
@endsection
