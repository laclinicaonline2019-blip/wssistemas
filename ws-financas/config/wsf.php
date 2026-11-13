<?php

/*
| WS Finanças em Dia — configuração da plataforma.
| Preços, limites e recursos dos planos ficam no BANCO (editáveis no painel do proprietário);
| aqui ficam só os padrões técnicos e as políticas.
*/

return [
    'brand' => [
        'name' => env('WSF_BRAND_NAME', 'WS Finanças em Dia'),
        'slogan' => 'Seu dinheiro em dia.',
        'support_email' => env('WSF_SUPPORT_EMAIL', 'suporte@example.com'),
        'company_legal' => env('WSF_LEGAL_NAME', 'WS Sistemas'),
    ],

    // development | homologation | production (faixa visível fora da produção)
    'stage' => env('APP_STAGE', 'production'),

    'security' => [
        // 2FA obrigatório para o proprietário da plataforma (sempre) e para administradores de clientes.
        'platform_admin_2fa' => (bool) env('WSF_2FA_PLATAFORMA', true),
        'account_admin_2fa' => (bool) env('WSF_2FA_ADMIN_CLIENTE', true),
        'max_login_attempts' => 5,
        'lockout_minutes' => 15,
        'audit_hmac_key' => env('AUDIT_HMAC_KEY'),
    ],

    'billing' => [
        'trial_days' => (int) env('WSF_TRIAL_DIAS', 14),
        // Dias de atraso tolerados antes de suspender (novos lançamentos bloqueados, dados preservados).
        'grace_days' => (int) env('WSF_CARENCIA_DIAS', 7),
    ],

    'lgpd' => [
        // Após o cancelamento, os dados ficam guardados por este prazo (política contratual) e depois são apagados.
        'retention_days_after_cancel' => (int) env('WSF_RETENCAO_APOS_CANCELAMENTO', 90),
        // Texto das mensagens do WhatsApp é anonimizado após este prazo (o lançamento continua).
        'whatsapp_body_days' => (int) env('WSF_RETENCAO_MENSAGENS', 180),
        // Trilha de auditoria (obrigações legais/fiscais).
        'audit_days' => (int) env('WSF_RETENCAO_AUDITORIA', 1825),
    ],

    'backup' => [
        'password' => env('BACKUP_PASSWORD'),
        'keep' => (int) env('BACKUP_MANTER', 14),
    ],

    'update' => [
        // Chave pública Ed25519 (base64) para exigir pacotes de atualização assinados. Vazio = aceita pacote sem
        // assinatura mediante confirmação do proprietário (conferindo o SHA-256 informado pelo fornecedor).
        'public_key' => env('WSF_UPDATE_PUBLIC_KEY', ''),
    ],

    'ai' => [
        // off | auto (só usa a IA quando as regras não entendem) — custo controlado por limite do plano.
        'mode' => env('WSF_IA', 'auto'),
        'effort' => env('WSF_IA_ESFORCO', 'low'),
    ],

    'categories' => [
        'despesa' => ['Moradia', 'Contas da casa', 'Alimentação', 'Delivery e lanches', 'Transporte', 'Saúde', 'Educação', 'Assinaturas', 'Lazer', 'Compras',
            'Dívidas', 'Fornecedores', 'Folha de pagamento', 'Impostos e taxas', 'Aluguel comercial', 'Serviços e manutenção', 'Marketing', 'Tarifas bancárias', 'Outros'],
        'receita' => ['Salário', 'Vendas', 'Serviços prestados', 'Recebimento de clientes', 'Rendimentos', 'Outras entradas'],
    ],
];
