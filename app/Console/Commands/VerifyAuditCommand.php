<?php

namespace App\Console\Commands;

use App\Core\Audit\AuditChain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VerifyAuditCommand extends Command
{
    protected $signature = 'aivexa:audit:verify {--scope= : company_id ou "platform" (padrão: todos)}';

    protected $description = 'Verifica a integridade (cadeia HMAC) da trilha de auditoria';

    public function handle(AuditChain $chain): int
    {
        $scopes = $this->option('scope') ? [$this->option('scope')] : $chain->scopes();
        $failed = 0;

        foreach ($scopes as $scope) {
            $r = $chain->verify($scope);
            $label = $scope === 'platform' ? 'plataforma' : (DB::table('companies')->where('id', $scope)->value('trade_name') ?? $scope);

            if ($r['ok']) {
                $this->components->twoColumnDetail($label, "<fg=green>íntegra</> ({$r['checked']} registros)");
            } else {
                $failed++;
                $this->components->twoColumnDetail($label, "<fg=red>VIOLADA</> em #{$r['broken_at']}: {$r['reason']}");
                Log::critical('Integridade da auditoria violada', ['scope' => $scope] + $r);
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
