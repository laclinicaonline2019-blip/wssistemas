<?php

/*
| Cobrança da assinatura das clínicas (Fase 16) — conta da PLATAFORMA (aivexaclinica),
| separada dos gateways que cada clínica usa para cobrar os pacientes.
|
| provider: mock (homologação — nada é cobrado de verdade) ou asaas (sandbox/produção).
*/

return [
    'provider' => env('PLATFORM_BILLING_PROVIDER', 'mock'),

    'asaas' => [
        'api_key' => env('PLATFORM_ASAAS_API_KEY'),
        'sandbox' => (bool) env('PLATFORM_ASAAS_SANDBOX', true),
        'webhook_token' => env('PLATFORM_ASAAS_WEBHOOK_TOKEN'),
    ],

    // Fatura de renovação emitida N dias antes do fim do período (ou do teste grátis).
    'invoice_days_before' => (int) env('BILLING_INVOICE_DAYS_BEFORE', 7),
    // Dias depois do vencimento até bloquear o acesso (o administrador continua podendo entrar para pagar).
    'suspend_after_days' => (int) env('BILLING_SUSPEND_AFTER_DAYS', 10),
    // Prazo de pagamento das faturas de upgrade.
    'upgrade_due_days' => 3,
];
