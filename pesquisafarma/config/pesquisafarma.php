<?php

return [
    'whatsapp_driver' => env('WHATSAPP_DRIVER', 'mock'),
    'webhook_secret' => env('WHATSAPP_WEBHOOK_SECRET', ''),
    'zapi' => ['instance' => env('ZAPI_INSTANCE'), 'token' => env('ZAPI_TOKEN'), 'client_token' => env('ZAPI_CLIENT_TOKEN')],
    'evolution' => ['url' => env('EVOLUTION_URL'), 'instance' => env('EVOLUTION_INSTANCE'), 'api_key' => env('EVOLUTION_API_KEY')],
    'meta' => ['phone_number_id' => env('META_PHONE_NUMBER_ID'), 'access_token' => env('META_ACCESS_TOKEN'), 'verify_token' => env('META_VERIFY_TOKEN')],
    'anthropic' => ['api_key' => env('ANTHROPIC_API_KEY'), 'model' => env('ANTHROPIC_MODEL', 'claude-opus-5-5'), 'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com')],
    'cep_lookup' => env('CEP_LOOKUP', true),
];
