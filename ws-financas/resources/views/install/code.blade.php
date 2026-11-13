@extends('install.layout')
@section('content')
<form class="card" method="post" action="/instalar/codigo">
  <h2>Código de instalação</h2>
  <p class="mt0">Para provar que você é o proprietário, abra no <b>cPanel → Gerenciador de Arquivos</b> o arquivo abaixo (dentro da pasta do sistema), copie o código e cole aqui:</p>
  <p><code style="overflow-wrap:anywhere">{{ $codeFile }}</code></p>
  <p class="small muted mt0">O código vale 2 horas. Após 5 tentativas erradas ele é trocado. O arquivo fica fora da pasta pública e é apagado ao concluir a instalação.</p>
  @isset($error)<div class="banner bad" role="alert">{{ $error }}</div>@endisset
  <div class="field"><label for="codigo">Código</label><input id="codigo" name="codigo" required autocomplete="off" autofocus placeholder="XXXX-XXXX-XXXX-XXXX-XXXX-XXXX" style="text-transform:uppercase"></div>
  <button class="btn primary" type="submit">Continuar</button>
</form>
@endsection
