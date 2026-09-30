@extends('print.layout-a4', ['title' => 'Teste de impressão A4', 'autoprint' => true])

@section('body')
    <h1 class="doc-title">Página de teste de impressão</h1>
    <p>Esta página valida o layout A4 utilizado por <strong>receitas, atestados, solicitações de exames, recibos e relatórios</strong>.</p>
    <table class="doc-table">
        <tr><th>Verificação</th><th>Esperado</th></tr>
        <tr><td>Margens</td><td>Borda pontilhada a 15 mm do papel, sem cortes</td></tr>
        <tr><td>Cabeçalho</td><td>Nome, endereço e contato da unidade</td></tr>
        <tr><td>Rodapé</td><td>Texto configurado e data/hora de emissão</td></tr>
        <tr><td>Nitidez</td><td>Texto preto legível em 10 pt</td></tr>
    </table>
    <div class="doc-signature">
        <div class="doc-signature__line"></div>
        <div>Assinatura / carimbo do profissional</div>
        <div class="doc-muted">Documentos clínicos reais incluirão identificação do profissional (CRM/UF) e, quando aplicável, assinatura digital ICP-Brasil.</div>
    </div>
@endsection
