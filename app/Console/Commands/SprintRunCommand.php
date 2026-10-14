<?php

namespace App\Console\Commands;

use App\Modules\Sprint\Services\NfseService;
use Illuminate\Console\Command;

/** Melhor Sprint: acompanha a autorização/cancelamento das NFS-e na prefeitura. Agendado a cada 10 minutos. */
class SprintRunCommand extends Command
{
    protected $signature = 'aivexa:sprint:run {--limit=50}';

    protected $description = 'Consulta as NFS-e do Melhor Sprint em processamento';

    public function handle(NfseService $nfse): int
    {
        $count = $nfse->runPending((int) $this->option('limit'));
        $this->info("{$count} NFS-e consultada(s).");

        return self::SUCCESS;
    }
}
