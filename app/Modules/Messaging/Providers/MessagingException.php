<?php

namespace App\Modules\Messaging\Providers;

use RuntimeException;

class MessagingException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = true)
    {
        parent::__construct($message);
    }
}
