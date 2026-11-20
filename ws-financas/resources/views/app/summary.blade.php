@extends('layouts.app')
@section('title', 'Resumo — WS Finanças em Dia')
@section('content')
<form class="blockhead" method="get" action="{{ route('summary') }}">
  <h2>Resumo</h2>
  <span class="inline-form">
    <select name="empresa" aria-label="Entidade" data-autosubmit>@foreach ($companies as $c)<option value="{{ $c->id }}" @selected($company?->id === $c->id)>{{ $c->person_type === 'pf' ? '👤' : '🏢' }} {{ $c->name }}</option>@endforeach</select>
    <select name="periodo" aria-label="Período" data-autosubmit>@foreach (\App\Http\Controllers\SummaryController::PERIODS as $k => $l)<option value="{{ $k }}" @selected($per === $k)>{{ $l }}</option>@endforeach</select>
    <noscript><button class="btn sm" type="submit">Ver</button></noscript>
  </span>
</form>
@if (! $facts)
  <div class="card">Nenhuma entidade ativa.</div>
@else
  @php($f = $facts)
  @php($prev = $f['anterior']['despesas'])
  @php($var = $prev > 0 ? round(($f['despesas'] - $prev) * 100 / $prev) : null)
  <p class="small muted mt0">{{ $f['empresa'] }} · {{ $f['periodo'] }} · cada CPF/CNPJ tem os próprios números — nada é misturado.</p>
  <div class="kpis">
    <div class="kpi"><span class="small muted">💵 Receitas</span><b class="num">{{ brl($f['receitas']) }}</b></div>
    <div class="kpi"><span class="small muted">💸 Despesas</span><b class="num">{{ brl($f['despesas']) }}</b>
      @if ($var !== null)<span class="small {{ $var > 0 ? 'up' : 'down' }}">{{ $var > 0 ? '▲' : '▼' }} {{ abs($var) }}% vs. período anterior</span>@endif</div>
    <div class="kpi"><span class="small muted">💰 Saldo</span><b class="num" @if($f['saldo'] < 0) style="color:#b42318" @endif>{{ brl($f['saldo']) }}</b></div>
    <div class="kpi"><span class="small muted">📅 Em aberto</span><b class="num">{{ brl($f['em_aberto']) }}</b></div>
    @if ($f['pendentes'] && can_do('despesas.aprovar'))<a class="kpi" href="{{ route('approvals.index') }}"><span class="small muted">⏳ Aguardando aprovação</span><b>{{ $f['pendentes'] }}</b></a>@endif
  </div>

  <div class="card">
    <h3 class="mt0">💡 Recomendações</h3>
    <ul class="insights">@foreach ($insights as $i)<li>{{ $i }}</li>@endforeach</ul>
    <p class="small muted mt0">Baseadas somente nos seus lançamentos. Valores de economia são estimativas, não garantias.</p>
  </div>

  @if ($f['metas'])
  <div class="card"><h3 class="mt0">🎯 Metas de gasto</h3>
    @foreach ($f['metas'] as $m)
      <div style="margin:8px 0"><div class="blockhead"><span>Meta {{ $m['rotulo'] }}</span><span class="num small">{{ brl($m['gasto']) }} de {{ brl($m['meta']) }} ({{ str_replace('.', ',', $m['pct']) }}%)</span></div>
        <div class="bar {{ $m['gasto'] > $m['meta'] ? 'over' : '' }}"><i style="width:{{ min(100, $m['pct']) }}%"></i></div></div>
    @endforeach
  </div>
  @elseif (can_do('empresas.gerenciar'))
  <div class="card small">🎯 Defina metas mensais e semanais de gasto em <a href="{{ route('companies.index') }}">Empresas e contas</a> para receber alertas quando estiver perto do limite.</div>
  @endif

  <div class="card"><h3 class="mt0">📊 Despesas por categoria</h3>
    @forelse ($f['categorias'] as $c)
      <div style="margin:6px 0"><div class="blockhead"><span>{{ \App\Services\CategoryGuesser::emoji($c['categoria']) }} {{ $c['categoria'] }} <span class="muted small">{{ $c['qtd'] }} lançamento(s)</span></span><span class="num">{{ brl($c['total']) }} <span class="muted small">{{ str_replace('.', ',', $c['pct']) }}%</span></span></div>
        <div class="bar"><i style="width:{{ min(100, $c['pct']) }}%"></i></div></div>
    @empty
      <p class="muted">Nenhuma despesa no período. Lance pelo WhatsApp (ex.: "100 almoço") ou em <a href="{{ route('app') }}">Lançamentos</a>.</p>
    @endforelse
  </div>

  @if ($f['centros'])
  <div class="card"><h3 class="mt0">🏷️ Centros de custo</h3>
    <div class="table-wrap"><table class="t"><tbody>@foreach ($f['centros'] as $c)<tr><td>{{ $c['centro'] }}</td><td class="num">{{ brl($c['total']) }}</td></tr>@endforeach</tbody></table></div></div>
  @endif

  @if ($f['recorrentes'])
  <div class="card"><h3 class="mt0">🔁 Gastos que se repetem</h3>
    <div class="table-wrap"><table class="t"><tbody>@foreach ($f['recorrentes'] as $r)<tr><td>{{ $r['descricao'] }}</td><td class="num">≈ {{ brl($r['valor']) }}/mês</td></tr>@endforeach</tbody></table></div></div>
  @endif
@endif
@endsection
