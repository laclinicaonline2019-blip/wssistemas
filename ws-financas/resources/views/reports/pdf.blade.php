<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><style>
body{font-family:"DejaVu Sans",sans-serif;font-size:10px;color:#13201b}
h1{font-size:16px;margin:0;color:#0c6b4d} h2{font-size:12px;margin:14px 0 4px}
table{width:100%;border-collapse:collapse} th,td{padding:4px 5px;border-bottom:1px solid #d8e2dd;text-align:left} th{background:#e8efeb;font-size:9px;text-transform:uppercase}
.n{text-align:right;white-space:nowrap} .muted{color:#5b6c64} .bad{color:#bf3328}
.box td{border:0;padding:2px 8px 2px 0}
</style></head><body>
<h1>WS Finanças em Dia — Relatório financeiro</h1>
<p class="muted">{{ $account->name }} · {{ $companies->pluck('name')->implode(', ') }} · {{ $r['de'] }}{{ $r['ate'] !== $r['de'] ? ' a '.$r['ate'] : '' }} · gerado em {{ now()->format('d/m/Y H:i') }}</p>
<table class="box"><tr><td>Entradas: <b>{{ brl($r['entradas'] * 100) }}</b></td><td>Saídas: <b>{{ brl($r['saidas'] * 100) }}</b></td><td>Resultado: <b class="{{ $r['resultado'] < 0 ? 'bad' : '' }}">{{ brl($r['resultado'] * 100) }}</b></td></tr>
<tr><td>Pagas: {{ brl($r['situacao']['pagas'] * 100) }}</td><td>Pendentes: {{ brl($r['situacao']['pendentes'] * 100) }}</td><td>Atrasadas: <span class="bad">{{ brl($r['situacao']['atrasadas'] * 100) }}</span></td></tr></table>
<h2>Despesas por categoria</h2>
<table><tr><th>Categoria</th><th class="n">Qtde</th><th class="n">Total</th></tr>
@foreach ($r['por_categoria'] as $c)<tr><td>{{ $c['categoria'] }}</td><td class="n">{{ $c['qtd'] }}</td><td class="n">{{ brl($c['total'] * 100) }}</td></tr>@endforeach</table>
@if (count($r['por_empresa']) > 1)
<h2>Por empresa</h2>
<table><tr><th>Empresa</th><th class="n">Entradas</th><th class="n">Saídas</th><th class="n">Resultado</th></tr>
@foreach ($r['por_empresa'] as $e)<tr><td>{{ $e['empresa'] }}</td><td class="n">{{ brl($e['entradas'] * 100) }}</td><td class="n">{{ brl($e['saidas'] * 100) }}</td><td class="n">{{ brl($e['resultado'] * 100) }}</td></tr>@endforeach</table>
@endif
<h2>Lançamentos</h2>
<table><tr><th>Data</th><th>Empresa</th><th>Tipo</th><th>Descrição</th><th>Categoria</th><th>Situação</th><th class="n">Valor</th></tr>
@foreach ($r['lancamentos'] as $l)<tr><td>{{ $l['data'] }}</td><td>{{ $l['empresa'] }}</td><td>{{ $l['tipo'] }}</td><td>{{ $l['descricao'] }}{{ $l['parcela'] && $l['parcela'] !== 'fixa' ? ' ('.$l['parcela'].')' : '' }}</td><td>{{ $l['categoria'] }}</td><td class="{{ $l['situacao'] === 'Atrasada' ? 'bad' : '' }}">{{ $l['situacao'] }}</td><td class="n">{{ brl($l['valor'] * 100) }}</td></tr>@endforeach
</table>
</body></html>
