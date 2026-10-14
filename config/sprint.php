<?php

/*
|--------------------------------------------------------------------------
| Melhor Sprint — sistema de split de pagamento
|--------------------------------------------------------------------------
|
| Comissão da plataforma sobre cada venda paga no Melhor Sprint. O Super Admin pode definir
| uma taxa própria por clínica (Plataforma → Melhor Sprint). Quando a carteira/subordinado da
| plataforma está configurado, a comissão é separada pelo PRÓPRIO gateway (split nativo):
|   - ASAAS: walletId da conta ASAAS da plataforma;
|   - Cielo API E-commerce: SubordinateMerchantId da plataforma no Split Cielo.
| Vendas em dinheiro/maquininha (ou sem carteira configurada) acumulam a comissão "a cobrar",
| listada no painel da plataforma.
|
*/

return [
    'brand' => 'Melhor Sprint',

    // Centésimos de % (150 = 1,50%).
    'commission_bp' => (int) env('SPRINT_COMMISSION_BP', 150),

    'platform' => [
        'asaas_wallet_id' => env('SPRINT_PLATFORM_ASAAS_WALLET'),
        'cielo_subordinate_id' => env('SPRINT_PLATFORM_CIELO_SUBORDINATE'),
    ],

    // Tela de confirmação: intervalo mínimo entre consultas ativas ao gateway (segundos).
    'poll_sync_seconds' => (int) env('SPRINT_POLL_SYNC_SECONDS', 15),
];
