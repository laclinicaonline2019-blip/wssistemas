@extends('layouts.admin')
@section('title', 'Sistema e backups')
@section('content')
<div class="grid2">
  <div class="card"><h3>Prontidão</h3>
    <div class="table-wrap" style="border:0"><table class="t"><tbody>@foreach ($checks as $c)<tr><td><span class="pill {{ $c['ok'] ? 'ok' : 'warn' }}">{{ $c['ok'] ? 'OK' : 'Atenção' }}</span> {{ $c['item'] }}</td><td class="small muted">{{ $c['info'] }}</td></tr>@endforeach</tbody></table></div></div>
  <div style="display:flex;flex-direction:column;gap:14px">
    <div class="card"><h3>Auditoria</h3><p class="mt0 small">{{ $audit['ok'] ? 'Cadeia íntegra — '.$audit['checked'].' registros conferidos.' : 'ATENÇÃO: cadeia quebrada no registro #'.$audit['broken_at'].'. Investigue alteração direta no banco.' }}</p></div>
    <form class="card" method="post" action="{{ route('admin.system.settings') }}">@csrf<h3>Políticas comerciais</h3>
      <div class="form">
        <div class="field"><label>Teste grátis (dias)</label><input type="number" name="trial_days" min="0" max="90" value="{{ $settings['trial_days'] }}"></div>
        <div class="field"><label>Carência antes de suspender (dias)</label><input type="number" name="grace_days" min="0" max="60" value="{{ $settings['grace_days'] }}"></div>
        <div class="field full"><label>Guardar dados após cancelamento (dias)</label><input type="number" name="retention" min="30" max="3650" value="{{ $settings['retention'] }}"></div>
      </div><button class="btn primary" type="submit">Salvar</button></form>
  </div>
</div>
<section class="block"><div class="blockhead"><h2>Backups</h2>
  <form method="post" action="{{ route('admin.system.backup') }}">@csrf<button class="btn primary" type="submit">Gerar backup agora e testar restauração</button></form></div>
<p class="small muted mt0">Automático: banco diário às 02:10; teste de restauração semanal. Arquivos criptografados (AES-256) em storage/app/backups — copie regularmente para fora do servidor (ver docs/BACKUP.md).</p>
<div class="table-wrap"><table class="t"><thead><tr><th>Arquivo</th><th class="n">Tamanho</th><th>Data</th></tr></thead><tbody>
@forelse ($files as $f)<tr><td>{{ $f['name'] }}</td><td class="n">{{ number_format($f['size']/1024, 1, ',', '.') }} KB</td><td>{{ date('d/m/Y H:i', $f['at']) }}</td></tr>@empty<tr><td colspan="3" class="muted">Nenhum backup ainda.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
