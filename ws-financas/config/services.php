<?php

return [
    // Inteligência artificial (Claude) — usada só quando o interpretador por regras não entende a mensagem.
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'model' => env('WSF_AI_MODEL', 'claude-opus-5-5'),
    ],

    // WhatsApp Business — API oficial (Cloud API da Meta). Um número da plataforma atende todos os clientes;
    // o cliente é identificado pelo telefone autorizado que enviou a mensagem.
    'whatsapp' => [
        'mode' => env('WHATSAPP_MODE', 'mock'), // mock | cloud
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v21.0'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'display_number' => env('WHATSAPP_DISPLAY_NUMBER'),
        // Modelo (template) aprovado pela Meta para lembretes fora da janela de 24 h. Vazio = sem lembretes ativos.
        'reminder_template' => env('WHATSAPP_REMINDER_TEMPLATE'),
        'reminder_template_lang' => env('WHATSAPP_REMINDER_TEMPLATE_LANG', 'pt_BR'),
    ],

    // Asaas — cobrança das assinaturas da plataforma.
    'asaas' => [
        'mode' => env('BILLING_MODE', 'mock'), // mock | asaas
        'api_key' => env('ASAAS_API_KEY'),
        'sandbox' => (bool) env('ASAAS_SANDBOX', true),
        'webhook_token' => env('ASAAS_WEBHOOK_TOKEN'),
    ],
];
