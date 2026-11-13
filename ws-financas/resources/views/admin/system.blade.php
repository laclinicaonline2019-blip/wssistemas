@extends('layouts.admin')
@section('title', 'Sistema e backups')
@section('content')
<div class="stats"><div class="stat"><label>Versão</label><b>{{ $version }}</b></div><div class="stat {{ $pending ? 'bad' : 'good' }}"><label>Migrações pendentes</label><b>{{ count($pending) }}</b></div></div>
@if ($pending)
<form class="card" method="post" action="{{ route('admin.system.migrate') }}" data-confirm="Aplicar as migrações pendentes agora?">@csrf
  <h3>Aplicar migrações pendentes</h3>
  <p class="small mt0">Use depois de uma atualização manual pelo Gerenciador de Arquivos. Um backup do banco é feito antes; o site fica em manutenção por alguns segundos.</p>
  <ul class="small">@foreach ($pending as $m)<li><code>{{ $m }}</code></li>@endforeach</ul>
  <div class="field"><label>Sua senha</label><input type="password" name="password" required autocomplete="current-password"></div>
  <button class="btn primary" type="submit">Aplicar migrações pendentes</button>
</form>
@endif
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
