<?php

namespace App\Core\Support;

use RuntimeException;

/** Regra de negócio violada — mensagem segura para exibir ao usuário (HTTP 422). */
class BusinessRule extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'regra')
    {
        parent::__construct($message);
    }
}
