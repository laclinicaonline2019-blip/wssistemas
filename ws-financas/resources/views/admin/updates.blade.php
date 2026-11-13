@extends('layouts.admin')
@section('title', 'Atualizações')
@section('content')
<div class="stats">
  <div class="stat"><label>Versão instalada</label><b>{{ $version }}</b></div>
  <div class="stat {{ $writable ? 'good' : 'bad' }}"><label>Atualizar pelo painel</label><b>{{ $writable && $zip ? 'Disponível' : 'Manual' }}</b></div>
  <div class="stat"><label>Assinatura exigida</label><b>{{ $signedRequired ? 'Sim' : 'Não' }}</b></div>
  <div class="stat"><label>Upload máx. pelo PHP</label><b>{{ $uploadMax }}</b></div>
</div>
@if (! $writable || ! $zip)
  <div class="banner bad">{{ ! $zip ? 'A extensão PHP "zip" está desativada.' : 'O PHP não pode trocar os arquivos do sistema nesta hospedagem.' }} Use a atualização manual pelo Gerenciador de Arquivos (docs/INSTALACAO-CPANEL.md).</div>
@endif
@if ($active)
  <div class="banner warn"><span>Há uma atualização em andamento ({{ $active['from'] }} → {{ $active['to'] }}).</span><a class="btn sm primary" href="{{ route('admin.updates.run', $active['id']) }}">Continuar</a></div>
@endif

<div class="card small">
  <b>Como atualizar</b>
  <ol style="margin:4px 0 0;padding-left:18px">
    <li>Envie o pacote <code>ws-financas-X.Y.Z.zip</code> abaixo — ou, se for maior que {{ $uploadMax }}, pelo <b>Gerenciador de Arquivos</b> para a pasta <code>{{ $inboxPath }}</code>.</li>
    <li>Clique em <b>Analisar</b>: o pacote é validado (versão, assinatura, caminhos, espaço) sem alterar nada.</li>
    <li>Confirme com sua senha. O sistema faz <b>backup do banco</b>, confere os arquivos, entra em manutenção por poucos segundos, troca os arquivos, atualiza o banco e confere tudo. Se algo falhar, <b>volta sozinho</b> para a versão anterior.</li>
    <li>O .env (senhas e chaves) e a pasta storage (dados, backups) <b>nunca</b> são alterados.</li>
  </ol>
</div>

<section class="block"><div class="blockhead"><h2>Pacotes disponíveis</h2></div>
<div class="table-wrap"><table class="t"><thead><tr><th>Arquivo</th><th class="n">Tamanho</th><th>Enviado em</th><th></th></tr></thead><tbody>
@forelse ($packages as $p)
  <tr><td>{{ $p['name'] }}</td><td class="n">{{ number_format($p['size'] / 1048576, 1, ',', '.') }} MB</td><td>{{ date('d/m/Y H:i', $p['at']) }}</td>
    <td class="right"><form class="inline" method="post" action="{{ route('admin.updates.analyze') }}">@csrf<input type="hidden" name="pacote" value="{{ $p['name'] }}"><button class="btn sm primary" type="submit" @disabled(!$writable || !$zip || $active)>Analisar</button></form>
      <form class="inline" method="post" action="{{ route('admin.updates.destroy') }}" data-confirm="Remover este pacote?">@csrf<input type="hidden" name="pacote" value="{{ $p['name'] }}"><button class="icon" type="submit">remover</button></form></td></tr>
@empty
  <tr><td colspan="4" class="muted">Nenhum pacote enviado.</td></tr>
@endforelse
</tbody></table></div>
<form class="card" method="post" action="{{ route('admin.updates.upload') }}" enctype="multipart/form-data">@csrf
  <div class="field"><label for="pk">Enviar pacote (.zip)</label><input id="pk" type="file" name="pacote" accept=".zip,application/zip" required></div>
  <div class="formacts"><button class="btn" type="submit">Enviar</button></div>
</form></section>

<section class="block"><h2>Histórico</h2>
<div class="table-wrap"><table class="t"><thead><tr><th>Início</th><th>Versões</th><th>Situação</th><th></th></tr></thead><tbody>
@php($lastDone = collect($history)->firstWhere('status', 'done'))
@forelse ($history as $h)
  <tr><td class="small">{{ \Carbon\Carbon::parse($h['started_at'])->format('d/m/Y H:i') }}</td><td>{{ $h['from'] }} → {{ $h['to'] }}</td>
    <td><span class="pill {{ $h['status'] === 'done' ? 'ok' : (in_array($h['status'], ['running','reverted','failed']) ? 'warn' : 'bad') }}">{{ ['running' => 'em andamento', 'done' => 'concluída', 'failed' => 'falhou (nada alterado)', 'failed_rolled_back' => 'falhou e foi revertida', 'failed_manual' => 'falhou — ver docs', 'reverted' => 'revertida'][$h['status']] ?? $h['status'] }}</span>
      @if (!empty($h['error']))<br><span class="small muted">{{ $h['error'] }}</span>@endif</td>
    <td class="right"><a class="small" href="{{ route('admin.updates.run', $h['id']) }}">detalhes</a>
      @if ($lastDone && $lastDone['id'] === $h['id'] && $h['to'] === $version)
        <details class="edit"><summary>Reverter para {{ $h['from'] }}</summary>
          <form method="post" action="{{ route('admin.updates.revert', $h['id']) }}" class="form" style="margin-top:8px" data-confirm="Reverter o sistema para a versão {{ $h['from'] }}?">@csrf
            <label class="inline-check full"><input type="radio" name="modo" value="codigo" checked> Voltar os arquivos e desfazer as mudanças de banco desta atualização (mantém os lançamentos feitos depois)</label>
            <label class="inline-check full"><input type="radio" name="modo" value="banco"> Voltar os arquivos e restaurar o backup do banco feito antes da atualização (lançamentos feitos depois serão perdidos)</label>
            <div class="field full"><label>Sua senha</label><input type="password" name="password" required autocomplete="current-password"></div>
            <div class="formacts full"><button class="btn danger" type="submit">Reverter</button></div>
          </form></details>
      @endif</td></tr>
@empty
  <tr><td colspan="4" class="muted">Nenhuma atualização feita pelo painel.</td></tr>
@endforelse
</tbody></table></div></section>
@endsection
