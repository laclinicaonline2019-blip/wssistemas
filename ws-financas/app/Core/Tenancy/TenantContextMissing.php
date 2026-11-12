<?php

namespace App\Core\Tenancy;

use RuntimeException;

class TenantContextMissing extends RuntimeException
{
    public function __construct(string $message = 'Operação sem contexto de cliente.')
    {
        parent::__construct($message);
    }
}
