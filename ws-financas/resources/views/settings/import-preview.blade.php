@extends('layouts.app')
@section('title', 'Prévia da importação')
@section('content')
@php($rows = $batch->rows)
@php($dups = collect($rows)->whereNotNull('dup')->count())
<div class="blockhead"><h2>Prévia — {{ $batch->filename }}</h2><span class="muted small">{{ $company->name }} · {{ count($rows) }} transação(ões){{ $dups ? ' · '.$dups.' possível(is) duplicada(s)' : '' }}</span></div>
@if ($batch->status !== 'previa')
  <div class="card">Esta importação já foi {{ $batch->status === 'importado' ? 'concluída' : 'cancelada' }}.</div>
@else
<form method="post" action="{{ route('import.statement.confirm', $batch->id) }}">@csrf
  <p class="small">Nada foi lançado ainda. Desmarque o que não deve entrar. Saídas viram <b>despesas pagas</b> e entradas viram <b>receitas</b>, na data do extrato.
    Linhas marcadas como duplicadas vêm desmarcadas.</p>
  @if ($centers->isNotEmpty())<div class="field" style="max-width:340px"><label>Centro de custo (todas as linhas)</label><select name="centro"><option value="">—</option>@foreach ($centers as $cc)<option value="{{ $cc->id }}">{{ $cc->name }}</option>@endforeach</select></div>@endif
  <div class="table-wrap"><table class="t"><thead><tr><th><input type="checkbox" checked aria-label="Marcar todas" onclick="document.querySelectorAll('.imp-row').forEach(c=>c.checked=this.checked)"></th><th>Data</th><th>Descrição</th><th class="n">Valor</th><th>Categoria</th><th></th></tr></thead><tbody>
  @foreach ($rows as $i => $r)
    <tr><td><input class="imp-row" type="checkbox" name="linhas[]" value="{{ $i }}" @checked(! $r['dup'])></td>
      <td class="nowrap">{{ \Carbon\Carbon::parse($r['data'])->format('d/m/Y') }}</td><td>{{ $r['desc'] }}</td>
      <td class="n num" style="color:{{ $r['tipo'] === 'despesa' ? '#b42318' : '#067647' }}">{{ $r['tipo'] === 'despesa' ? '−' : '+' }}{{ brl($r['valor_cents']) }}</td>
      <td><select name="cat[{{ $i }}]" aria-label="Categoria">@foreach ($r['tipo'] === 'despesa' ? $catsDesp : $catsRec as $c)<option @selected($c === $r['cat'])>{{ $c }}</option>@endforeach</select></td>
      <td>@if($r['dup'] === 'importada')<span class="pill bad">já importada</span>@elseif($r['dup'])<span class="pill warn">parecida com lançamento existente</span>@endif</td></tr>
  @endforeach
  </tbody></table></div>
  <div class="formacts"><button class="btn primary" type="submit">Importar selecionadas</button></div>
</form>
<form method="post" action="{{ route('import.statement.cancel', $batch->id) }}">@csrf<button class="btn" type="submit">Cancelar importação</button></form>
@endif
@endsection
