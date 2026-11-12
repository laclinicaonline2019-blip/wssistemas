@extends('layouts.app')
@section('title', 'Privacidade e dados')
@section('content')
<div class="blockhead"><h2>Privacidade e seus dados (LGPD)</h2></div>
<div class="grid2">
  <div class="card"><h3>Exportar meus dados</h3><p class="mt0 small muted">Baixe um arquivo com todos os dados do cliente: empresas, usuários, contas, lançamentos, dívidas e números autorizados (portabilidade).</p>
    <a class="btn primary" href="{{ route('lgpd.export') }}">Baixar meus dados (JSON)</a></div>
  <div class="card"><h3>Exclusão</h3><p class="mt0 small muted">Ao cancelar a assinatura os dados ficam guardados por {{ config('wsf.lgpd.retention_days_after_cancel') }} dias (para consulta e exportação) e depois são eliminados de forma definitiva, exceto registros que a lei obriga a manter (como os de cobrança).</p>
    @if ($account->purge_after)<p class="banner warn">Exclusão definitiva agendada para {{ $account->purge_after->format('d/m/Y') }}.</p>@endif
    <a class="btn" href="{{ route('billing.show') }}">Ir para Assinatura</a></div>
</div>
<p class="small muted">Leia a <a href="{{ route('privacy') }}">política de privacidade</a>. Para outros pedidos (correção, informação sobre compartilhamento), escreva para {{ config('wsf.brand.support_email') }}.</p>
@endsection
