<?php

return [

    'brand' => [
        'name' => env('APP_BRAND_NAME', 'AivexaClínica'),
        'short' => 'Aivexa',
    ],

    /*
    | Estágio do ambiente — sempre exibido na interface quando != production.
    | development | testing | homologation | production
    */
    'stage' => env('APP_STAGE', 'development'),

    'security' => [
        // Tentativas de login por e-mail+IP por minuto (rate limit).
        'login_rate_per_minute' => (int) env('LOGIN_RATE_PER_MINUTE', 5),
        // Falhas consecutivas até bloqueio temporário da conta.
        'lockout_threshold' => (int) env('LOGIN_LOCKOUT_THRESHOLD', 10),
        'lockout_minutes' => (int) env('LOGIN_LOCKOUT_MINUTES', 15),
        // 2FA obrigatório para Super Admin (recomendado sempre true em produção).
        'super_admin_requires_2fa' => (bool) env('SUPER_ADMIN_REQUIRES_2FA', true),
        // Verificar senhas vazadas (Have I Been Pwned, k-anonymity).
        'password_breach_check' => (bool) env('PASSWORD_BREACH_CHECK', false),
        'password_min_length' => (int) env('PASSWORD_MIN_LENGTH', 10),
        // Validade dos tokens de API (minutos).
        'api_token_ttl_minutes' => (int) env('API_TOKEN_TTL_MINUTES', 720),
        'hsts' => (bool) env('SECURITY_HSTS', false),
        'force_https' => (bool) env('FORCE_HTTPS', false),
    ],
];
