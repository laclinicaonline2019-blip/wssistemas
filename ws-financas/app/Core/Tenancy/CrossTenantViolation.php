<?php

namespace App\Core\Tenancy;

use RuntimeException;

class CrossTenantViolation extends RuntimeException
{
    public function __construct(string $message = 'Tentativa de acesso a dados de outro cliente ou empresa.')
    {
        parent::__construct($message);
    }
}
