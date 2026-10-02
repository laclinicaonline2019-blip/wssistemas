<?php

namespace App\Modules\Ai\Providers;

final class AgentResult
{
    public function __construct(
        public readonly string $text,
        public readonly string $stopReason,
        public readonly bool $refused = false,
    ) {}
}
