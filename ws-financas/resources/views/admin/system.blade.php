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
    <div class="card"><h3>Auditoria</h3>
      @php($rep = $audit['report'])
      @php($head = $audit['head'])
      <p class="mt0 small">Registros: <b>{{ $audit['total'] }}</b> · Formato de gravação: <b>{{ $head ? 'v'.$head->write_version : 'v1 (estrutura 1.1.1 pendente)' }}</b>{{ $head && $head->write_version >= 2 ? ' desde '.$head->activated_v2_at : '' }}</p>
      @if ($audit['pending'])
        <p class="small mt0"><span class="pill warn">em andamento</span> {{ $audit['pending']['checked'] }} registros conferidos.</p>
      @elseif ($rep)
        @php($problems = count($rep['problemas'] ?? []))
        <p class="small mt0"><span class="pill {{ $problems ? 'bad' : 'ok' }}">{{ $problems ? 'problemas' : 'íntegra' }}</span>
          última verificação {{ \Carbon\Carbon::parse($rep['gerado_em'])->format('d/m/Y H:i') }} — {{ $rep['registros'] }} registros:
          @foreach (($rep['resumo'] ?? []) as $st => $n){{ \App\Core\Audit\ChainVerifier::LABELS[$st] ?? $st }}: {{ $n }}@if(! $loop->last) · @endif @endforeach</p>
        @if (!empty($rep['compatibilidade']))<details class="edit small"><summary>{{ count($rep['compatibilidade']) }} registro(s) v1 conferido(s) por compatibilidade</summary>
          <ul style="margin:6px 0 0;padding-left:18px">@foreach ($rep['compatibilidade'] as $c)<li>#{{ $c['id'] }} {{ $c['action'] }} — {{ $c['method'] }} ({{ $c['attempts'] }} tentativas)</li>@endforeach</ul></details>@endif
        @if ($problems)<ul class="small" style="margin:0;padding-left:18px">@foreach ($rep['problemas'] as $p)<li>#{{ $p['id'] }} {{ $p['action'] }} — <b>{{ \App\Core\Audit\ChainVerifier::LABELS[$p['status']] ?? $p['status'] }}</b> {{ $p['detail'] ?? '' }}</li>@endforeach</ul>@endif
        <p class="small muted mt0">Relatório assinado: storage/app/auditoria/{{ $rep['arquivo'] }}</p>
      @else
        <p class="small muted mt0">Ainda não verificada nesta versão.</p>
      @endif
      <form method="post" action="{{ route('admin.system.audit.verify') }}">@csrf<button class="btn sm primary" type="submit">{{ $audit['pending'] ? 'Continuar verificação' : 'Verificar auditoria' }}</button></form>
      <p class="small mt0 muted">Âncora diária por e-mail: {{ $audit['anchor'] ? 'última #'.$audit['anchor']['last_id'].' em '.$audit['anchor']['gerada_em'] : 'nenhuma ainda' }}.</p>
      <form method="post" action="{{ route('admin.system.audit.anchor') }}">@csrf<button class="btn sm" type="submit">Gerar e enviar âncora agora</button></form>
      <details class="edit"><summary>Conferir âncora recebida por e-mail</summary>
        <form method="post" action="{{ route('admin.system.audit.anchor_check') }}" class="form" style="margin-top:8px">@csrf
          <div class="field full"><label>Cole o bloco da âncora (entre BEGIN e END)</label><textarea name="ancora" required></textarea></div>
          <div class="formacts full"><button class="btn sm" type="submit">Conferir</button></div></form></details>
      @if ($head && $head->write_version < 2)
        <details class="edit"><summary>Ativar formato v2 (irreversível)</summary>
          <form method="post" action="{{ route('admin.system.audit.activate') }}" class="form" style="margin-top:8px">@csrf
            <p class="full small mt0">Ative só depois de validar a fase 1 em homologação. Após ativar, nenhum processo grava no formato antigo e não é possível voltar o sistema para versões anteriores à 1.1.1. {{ $audit['hasKey'] ? '' : 'Defina AUDIT_HMAC_KEY no .env antes.' }}</p>
            <div class="field"><label>Digite ATIVAR V2</label><input name="confirm" required autocomplete="off"></div>
            <div class="field"><label>Sua senha</label><input type="password" name="password" required autocomplete="current-password"></div>
            <div class="formacts full"><button class="btn danger" type="submit" @disabled(! $audit['hasKey'])>Ativar v2</button></div>
          </form></details>
      @endif
    </div>
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
