<?php

namespace App\Modules\Sprint\Jobs;

use App\Core\Tenancy\TenantContext;
use App\Modules\Sprint\Models\SprintSale;
use App\Modules\Sprint\Services\NfseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/** NFS-e automática da venda quitada (fora da transação do pagamento). */
class IssueSprintNfse implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1; // falha fica registrada na nota (status "erro") e pode ser reemitida na tela

    public function __construct(public readonly string $companyId, public readonly string $saleId) {}

    public function handle(TenantContext $context, NfseService $nfse): void
    {
        $context->runFor($this->companyId, function () use ($nfse) {
            try {
                $nfse->issue(null, SprintSale::query()->findOrFail($this->saleId));
            } catch (Throwable $e) {
                Log::warning('NFS-e automática do Melhor Sprint não emitida', ['sale' => $this->saleId, 'error' => $e->getMessage()]);
            }
        });
    }
}
