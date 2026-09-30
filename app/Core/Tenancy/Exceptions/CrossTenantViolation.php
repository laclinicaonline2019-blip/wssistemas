<?php

namespace App\Core\Tenancy\Exceptions;

use RuntimeException;

class CrossTenantViolation extends RuntimeException
{
    public function __construct(string $message = 'Tentativa de gravar dado em outra empresa (tenant).')
    {
        parent::__construct($message);
    }
}
