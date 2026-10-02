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

    // Token do instalador web (/instalar). Mínimo 16 caracteres; vazio = instalador desativado.
    'install_token' => env('INSTALL_TOKEN'),

    // Portal do paciente (Fase 10).
    'portal' => [
        'activation_ttl_hours' => (int) env('PORTAL_ACTIVATION_TTL_HOURS', 72),
        'reset_ttl_minutes' => (int) env('PORTAL_RESET_TTL_MINUTES', 60),
    ],

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

    /*
    | Tipos de senha da fila (padrão). Prioridade legal: Lei 10.048/2000
    | (idosos 60+, gestantes, lactantes, pessoas com deficiência, com criança de colo)
    | e Lei 13.466/2017 (80+ têm preferência sobre os demais idosos).
    */
    'queue_types' => [
        'geral' => ['prefix' => 'A', 'label' => 'Atendimento geral', 'priority' => false],
        'convenio' => ['prefix' => 'C', 'label' => 'Convênio', 'priority' => false],
        'retorno' => ['prefix' => 'R', 'label' => 'Retorno', 'priority' => false],
        'prioridade' => ['prefix' => 'P', 'label' => 'Prioridade (Lei 10.048/2000)', 'priority' => true],
    ],

    /*
    | Anexos do paciente (PDF/imagens) — disco privado. Na HostGator confirme no
    | "Select PHP Version → Options" que upload_max_filesize/post_max_size ≥ este valor.
    */
    'uploads' => [
        'max_kb' => (int) env('UPLOAD_MAX_KB', 10240),
    ],

    /*
    | Assinatura digital ICP-Brasil (receita/atestado digitais). "none" = documentos
    | impressos e assinados de próprio punho. Provedores em nuvem: integração futura.
    */
    'signature' => [
        'driver' => env('SIGNATURE_DRIVER', 'none'),
    ],

    /*
    | Pagamentos online. O gateway MOCK (simulação) fica bloqueado em produção,
    | salvo liberação explícita para demonstração.
    */
    'payments' => [
        'allow_mock_in_production' => (bool) env('PAYMENTS_ALLOW_MOCK_IN_PRODUCTION', false),
    ],
];
