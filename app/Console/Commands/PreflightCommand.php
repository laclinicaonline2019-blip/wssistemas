<?php

namespace App\Console\Commands;

use App\Core\Health\ReadinessChecker;
use Illuminate\Console\Command;

/** Verificação de prontidão para homologação/produção (falha com erro; --strict também com avisos). */
class PreflightCommand extends Command
{
    protected $signature = 'aivexa:preflight {--strict : falha também com avisos} {--json : saída em JSON}';

    protected $description = 'Verifica se o ambiente está pronto para homologação/produção';

    public function handle(ReadinessChecker $checker): int
    {
        $items = $checker->run();
        $sum = $checker->summary($items);
        if ($this->option('json')) {
            $this->line(json_encode(['summary' => $sum, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->table(['Área', 'Item', 'Situação', 'Detalhe', 'Como corrigir'], array_map(fn ($i) => [$i['area'], $i['item'],
                ['ok' => '✔ ok', 'warn' => '! aviso', 'error' => '✘ ERRO'][$i['level']], $i['detail'], $i['level'] === 'ok' ? '' : $i['fix']], $items));
            $this->line("Resultado: {$sum['ok']} ok · {$sum['warn']} aviso(s) · {$sum['error']} erro(s)");
        }

        return $sum['error'] || ($this->option('strict') && $sum['warn']) ? self::FAILURE : self::SUCCESS;
    }
}
