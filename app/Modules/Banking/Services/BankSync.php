<?php

namespace App\Modules\Banking\Services;

use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatement;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Throwable;

/** Sincronização Open Finance: últimos dias desde a última leitura (com sobreposição — sem duplicar). */
class BankSync
{
    public function __construct(private readonly PluggyClient $pluggy, private readonly StatementImporter $importer) {}

    public function sync(?User $actor, BankAccount $account): BankStatement
    {
        $from = ($account->last_synced_at ? CarbonImmutable::parse($account->last_synced_at)->subDays(3) : CarbonImmutable::now()->subDays(30))->toDateString();
        $to = CarbonImmutable::now('America/Sao_Paulo')->toDateString();

        try {
            $statement = $this->importer->store($actor, $account, $this->pluggy->fetch($account, $from, $to), 'openfinance');
            $account->forceFill(['last_synced_at' => now(), 'sync_error' => null])->saveQuietly();

            return $statement;
        } catch (Throwable $e) {
            $account->forceFill(['sync_error' => mb_substr($e->getMessage(), 0, 500)])->saveQuietly();

            throw $e;
        }
    }
}
