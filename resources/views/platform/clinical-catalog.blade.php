@extends('layouts.app', ['title' => 'Bases clínicas'])

@section('content')
<div class="page-head"><div><h1>Bases clínicas</h1><p>Catálogos globais compartilhados por todas as clínicas (somente leitura para elas).</p></div></div>

<div class="grid grid-2">
    <section class="card">
        <div class="card__head"><h2>CID-10</h2><span class="badge">{{ number_format($cid['total'], 0, ',', '.') }} códigos</span></div>
        <div class="card__body stack">
            @if ($cid['sample'] > 0)
                <div class="alert alert-warning small">{{ $cid['sample'] }} códigos são <strong>dados de exemplo</strong>. Importe a tabela oficial do DATASUS para uso em produção.</div>
            @endif
            <p class="small text-2">Baixe em <strong>datasus.saude.gov.br → CID-10 → arquivos CSV</strong> e envie o arquivo <code>CID-10-SUBCATEGORIAS.CSV</code> (e, se quiser, <code>CID-10-CATEGORIAS.CSV</code>). Também aceita CSV simples <code>codigo;descricao</code>. Descrições existentes são atualizadas; diagnósticos já registrados guardam cópia do texto e nunca mudam.</p>
            <form method="post" action="{{ route('platform.catalog.cid') }}" enctype="multipart/form-data" class="stack">
                @csrf
                <div class="row">
                    <label class="sr-only" for="cid-file">Arquivo CSV</label>
                    <input id="cid-file" type="file" name="file" accept=".csv,.txt" class="input" required>
                    <label class="sr-only" for="cid-version">Versão</label>
                    <input id="cid-version" name="version" value="CID-10" class="input w-auto" maxlength="20" required>
                </div>
                @error('file')<div class="field-error">{{ $message }}</div>@enderror
                <div><button class="btn btn-primary" type="submit">Importar CID</button></div>
            </form>
        </div>
    </section>

    <section class="card">
        <div class="card__head"><h2>Medicamentos (base global)</h2><span class="badge">{{ number_format($medications['total'], 0, ',', '.') }} itens</span></div>
        <div class="card__body stack">
            @if ($medications['sample'] > 0)
                <div class="alert alert-warning small">{{ $medications['sample'] }} itens são <strong>dados de exemplo</strong>.</div>
            @endif
            <p class="small text-2">CSV separado por <code>;</code> com cabeçalho:<br><code>principio_ativo;nome_comercial;apresentacao;concentracao;fabricante;via;posologia;controle</code><br>
                <code>controle</code>: vazio/none, antimicrobial, A1, A2, A3, B1, B2, C1–C5 (Portaria SVS/MS 344/98). Itens repetidos são ignorados.</p>
            <form method="post" action="{{ route('platform.catalog.medications') }}" enctype="multipart/form-data" class="stack">
                @csrf
                <label class="sr-only" for="med-file">Arquivo CSV</label>
                <input id="med-file" type="file" name="file" accept=".csv,.txt" class="input" required>
                <div><button class="btn btn-primary" type="submit">Importar medicamentos</button></div>
            </form>
        </div>
    </section>
</div>
@endsection
