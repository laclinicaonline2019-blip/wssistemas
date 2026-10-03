<?php

namespace App\Modules\Banking\Parsers;

use App\Core\Support\BusinessRuleViolation;

class StatementParseException extends BusinessRuleViolation
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'statement_parse');
    }
}
