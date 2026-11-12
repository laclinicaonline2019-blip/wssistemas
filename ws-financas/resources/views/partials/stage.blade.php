@if (config('wsf.stage') !== 'production')
<div class="stagebar">{{ config('wsf.stage') === 'homologation' ? 'Ambiente de HOMOLOGAÇÃO — use só dados de teste' : 'Ambiente de desenvolvimento' }}</div>
@endif
