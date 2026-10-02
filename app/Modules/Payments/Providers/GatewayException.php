<?php

namespace App\Modules\Payments\Providers;

use App\Core\Support\BusinessRuleViolation;

/** Erro de comunicação/validação com o gateway (mensagem segura para o usuário). */
class GatewayException extends BusinessRuleViolation
{
    public function __construct(string $message, string $code = 'gateway_error')
    {
        parent::__construct($message, $code, 502);
    }
}
