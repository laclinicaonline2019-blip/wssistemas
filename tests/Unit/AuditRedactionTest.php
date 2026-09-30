<?php

namespace Tests\Unit;

use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\TenantContext;
use PHPUnit\Framework\TestCase;

class AuditRedactionTest extends TestCase
{
    public function test_sensitive_keys_are_redacted_recursively(): void
    {
        $logger = new AuditLogger(new TenantContext);

        $out = $logger->redact([
            'name' => 'Ana',
            'password' => 'x',
            'nested' => ['two_factor_secret' => 'y', 'ok' => 1, 'Token' => 'z'],
        ]);

        $this->assertSame('Ana', $out['name']);
        $this->assertSame(AuditLogger::REDACTED, $out['password']);
        $this->assertSame(AuditLogger::REDACTED, $out['nested']['two_factor_secret']);
        $this->assertSame(AuditLogger::REDACTED, $out['nested']['Token']);
        $this->assertSame(1, $out['nested']['ok']);
    }
}
