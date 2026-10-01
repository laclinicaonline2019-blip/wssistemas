<?php

namespace App\Console\Commands;

use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\TenantContext;
use App\Modules\Clinical\Services\CidService;
use App\Modules\Clinical\Services\MedicationService;
use Illuminate\Console\Command;

/** Importa as bases clínicas globais (alternativa à tela Plataforma → Bases clínicas). */
class ImportClinicalCatalogCommand extends Command
{
    protected $signature = 'aivexa:catalog:import {type : cid ou medicamentos} {file : caminho do CSV} {--cid-version=CID-10}';

    protected $description = 'Importa a CID-10 (CSV do DATASUS) ou a base global de medicamentos';

    public function handle(TenantContext $context, CidService $cids, MedicationService $medications, AuditLogger $audit): int
    {
        $file = $this->argument('file');

        if (! is_readable($file)) {
            $this->error("Arquivo não encontrado: {$file}");

            return self::FAILURE;
        }

        return $context->runAsSystem(function () use ($file, $cids, $medications, $audit) {
            switch ($this->argument('type')) {
                case 'cid':
                    $stats = $cids->import($file, $this->option('cid-version'));
                    $audit->record('catalog.cid_imported', metadata: $stats + ['version' => $this->option('cid-version'), 'channel' => 'cli']);
                    $this->info("CID: {$stats['created']} novos, {$stats['updated']} atualizados, {$stats['skipped']} ignorados.");
                    break;
                case 'medicamentos':
                    $stats = $medications->import($file);
                    $audit->record('catalog.medications_imported', metadata: $stats + ['channel' => 'cli']);
                    $this->info("Medicamentos: {$stats['created']} novos, {$stats['skipped']} ignorados.");
                    break;
                default:
                    $this->error('Tipo inválido: use "cid" ou "medicamentos".');

                    return self::FAILURE;
            }

            return self::SUCCESS;
        });
    }
}
