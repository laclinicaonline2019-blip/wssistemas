@extends('layouts.app')
@section('title', 'Importar dados locais')
@section('content')
<div class="blockhead"><h2>Importar extrato do banco</h2><span class="muted small">Opcional — OFX, CSV, Excel (.xlsx) ou PDF</span></div>
<form class="card" method="post" action="{{ route('import.statement.upload') }}" enctype="multipart/form-data">@csrf
  <p class="small mt0">Envie o extrato baixado no site ou aplicativo do banco. Antes de lançar, você vê uma <b>prévia</b>, escolhe o que entra e as categorias.
    Transações já importadas ou já lançadas (por exemplo, pelo WhatsApp) são marcadas para <b>não duplicar</b>.
    PDF: somente extrato com texto (gerado pelo banco); PDF escaneado não é lido.</p>
  <div class="form">
    <div class="field"><label>Para qual entidade?</label><select name="empresa" required>@foreach ($companies as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
    <div class="field"><label>Arquivo do extrato</label><input type="file" name="extrato" required accept=".ofx,.qfx,.csv,.txt,.xlsx,.pdf"></div>
  </div>
  <div class="formacts"><button class="btn primary" type="submit">Ver prévia</button></div>
</form>
@if ($batches->isNotEmpty())
<div class="table-wrap"><table class="t"><thead><tr><th>Data</th><th>Arquivo</th><th>Situação</th></tr></thead><tbody>
@foreach ($batches as $b)<tr><td>{{ $b->created_at->format('d/m/Y H:i') }}</td><td>{{ $b->filename }} <span class="muted small">{{ strtoupper($b->format) }}</span></td>
  <td>@if($b->status === 'previa')<a href="{{ route('import.statement.show', $b->id) }}">prévia aguardando confirmação</a>@elseif($b->status === 'importado'){{ $b->imported }} importado(s), {{ $b->duplicates }} duplicado(s) ignorado(s)@else cancelada @endif</td></tr>@endforeach
</tbody></table></div>
@endif
<div class="blockhead"><h2>Importar dados do WS Finanças antigo</h2></div>
<div class="card small">
  <p class="mt0">O sistema anterior guardava os lançamentos só no navegador. Aqui você traz tudo para o banco de dados, com segurança:</p>
  <ul style="margin:0;padding-left:18px">
    <li><b>Nada é apagado</b> — nem no navegador, nem no sistema.</li>
    <li>Pode importar mais de uma vez: o que já foi importado <b>não duplica</b>.</li>
    <li>Lançamentos de exemplo são ignorados.</li>
  </ul>
</div>
<form class="card" method="post" action="{{ route('import.store') }}" enctype="multipart/form-data" id="import-form">@csrf
  <h3>1. Para qual empresa?</h3>
  <div class="field"><select name="empresa" required>@foreach ($companies as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
  <h3>2. De onde vêm os dados?</h3>
  <div id="local-box" class="banner info" hidden><span id="local-info"></span><button type="button" class="btn sm primary" id="use-local">Usar dados deste navegador</button></div>
  <div class="field"><label for="arquivo">Arquivo exportado (.json)</label><input id="arquivo" type="file" name="arquivo" accept=".json,application/json,text/plain"></div>
  <input type="hidden" name="conteudo" id="conteudo">
  <p class="small muted mt0">Para gerar o arquivo, abra o <a href="{{ asset('ferramentas/exportar-dados-locais.html') }}" target="_blank" rel="noopener">exportador de dados locais</a> no mesmo navegador e no mesmo endereço onde você usava o WS Finanças antigo, e clique em “Baixar meus dados”. Veja o passo a passo em docs/MIGRACAO.md.</p>
  <div class="formacts"><button class="btn primary" type="submit">Importar</button></div>
</form>
@if ($history->isNotEmpty())
<section class="block"><h3>Importações anteriores</h3>
<div class="table-wrap"><table class="t"><thead><tr><th>Data</th><th>Resultado</th></tr></thead><tbody>
@foreach ($history as $h)<tr><td>{{ $h->created_at->format('d/m/Y H:i') }}</td><td class="small">{{ collect($h->summary)->map(fn($v,$k)=>str_replace('_',' ',$k).': '.$v)->implode(' · ') }}</td></tr>@endforeach
</tbody></table></div></section>
@endif
@endsection
@push('scripts')<script src="{{ asset('assets/js/import.js') }}"></script>@endpush
