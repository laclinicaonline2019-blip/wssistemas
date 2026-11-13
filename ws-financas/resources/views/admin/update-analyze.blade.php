@extends('layouts.admin')
@section('title', 'Analisar atualização')
@section('content')
<div class="card">
  <h2>Pacote válido: {{ $version }} → {{ $info['manifest']['version'] }}</h2>
  <div class="table-wrap" style="border:0"><table class="t"><tbody>
    <tr><td>Arquivo</td><td>{{ $name }}</td></tr>
    <tr><td>Assinatura digital</td><td>@if($info['signature_ok'] === true)<span class="pill ok">válida</span>@elseif($info['signed'])<span class="pill warn">presente (sem chave pública configurada para conferir)</span>@else<span class="pill warn">sem assinatura</span>@endif</td></tr>
    <tr><td>SHA-256 do pacote</td><td><code style="overflow-wrap:anywhere">{{ $info['sha256'] }}</code></td></tr>
    <tr><td>Arquivos</td><td>{{ number_format($info['entries'], 0, ',', '.') }} ({{ number_format($info['bytes'] / 1048576, 1, ',', '.') }} MB)</td></tr>
    <tr><td>Requer</td><td>PHP {{ $info['manifest']['php_min'] ?? '—' }} · a partir da versão {{ $info['manifest']['min_from'] ?? '—' }}</td></tr>
    @if (!empty($info['manifest']['notes']))<tr><td>Novidades</td><td class="small">{{ $info['manifest']['notes'] }}</td></tr>@endif
  </tbody></table></div>
  @foreach ($info['warnings'] as $w)<div class="banner warn">{{ $w }}</div>@endforeach
  <form method="post" action="{{ route('admin.updates.start') }}" class="form">@csrf
    <input type="hidden" name="pacote" value="{{ $name }}">
    @unless ($info['signed'])<label class="inline-check full"><input type="checkbox" name="sem_assinatura" value="1" required> Conferi que o SHA-256 acima é igual ao informado pelo fornecedor do pacote.</label>@endunless
    <label class="inline-check full"><input type="checkbox" name="ciente" value="1" required> Entendo que o site fica em manutenção por alguns segundos durante a troca e que um backup do banco será feito antes.</label>
    <div class="field"><label for="pw">Sua senha (confirmação)</label><input id="pw" type="password" name="password" required autocomplete="current-password"></div>
    <div class="formacts full"><a class="btn" href="{{ route('admin.updates') }}">Cancelar</a><button class="btn primary" type="submit">Iniciar atualização</button></div>
  </form>
</div>
@endsection
