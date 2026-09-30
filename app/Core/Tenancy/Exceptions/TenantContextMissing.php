<?php

namespace App\Core\Tenancy\Exceptions;

use RuntimeException;

class TenantContextMissing extends RuntimeException
{
    public function __construct(string $message = 'Operação em dado de clínica sem contexto de tenant definido.')
    {
        parent::__construct($message);
    }
}
