<?php

namespace App\Services\Import;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRule;
use App\Models\Company;
use App\Models\Entry;
use App\Models\ImportBatch;
use App\Services\CategoryGuesser;
use App\Services\FinanceService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Importação de extrato em duas etapas — nada é gravado como lançamento sem a confirmação do cliente:
 *  1. prévia: o arquivo é lido, cada transação recebe categoria sugerida e marcação de possível duplicidade;
 *  2. confirmação: só as linhas escolhidas viram lançamentos (despesas pagas / receitas), com origem "import".
 * Duplicidade: mesma identificação já importada (inclusive de outro arquivo do mesmo banco) ou mesmo dia + valor + tipo
 * + descrição já lançados (por exemplo, pelo WhatsApp). Reenviar o mesmo arquivo não duplica nada.
 */
class StatementImport
{
    public function __construct(private readonly StatementParser $parser, private readonly FinanceService $finance, private readonly AuditLogger $audit) {}

    public function preview(Company $company, UploadedFile $file, ?string $userId): ImportBatch
    {
        $parsed = $this->parser->parse($file->getRealPath(), $file->getClientOriginalName());
        $rows = $parsed['rows'];
        $refs = array_column($rows, 'id');
        $known = Entry::withPending()->withTrashed()->where('company_id', $company->id)->whereIn('external_ref', $refs)->pluck('external_ref')->flip();
        $dates = array_unique(array_column($rows, 'data'));
        $existing = Entry::query()->where('company_id', $company->id)->whereIn('due_date', $dates)->get(['due_date', 'amount_cents', 'type', 'description']);
        $index = [];
        foreach ($existing as $e) {
            $index[$e->due_date->toDateString().'|'.$e->amount_cents.'|'.$e->type][] = CategoryGuesser::norm($e->description);
        }
        foreach ($rows as &$r) {
            $r['cat'] = $r['tipo'] === 'despesa' ? CategoryGuesser::guess($r['desc']) : CategoryGuesser::guessIncome($r['desc']);
            $same = $index[$r['data'].'|'.$r['valor_cents'].'|'.$r['tipo']] ?? [];
            $r['dup'] = isset($known[$r['id']]) ? 'importada' : ($same ? 'parecida' : null);
        }
        unset($r);

        return ImportBatch::query()->create(['company_id' => $company->id, 'user_id' => $userId, 'format' => $parsed['format'],
            'filename' => mb_substr(basename($file->getClientOriginalName()), 0, 180), 'checksum' => hash_file('sha256', $file->getRealPath()),
            'rows' => $rows, 'status' => 'previa']);
    }

    /**
     * @param  list<int>  $selected  índices das linhas confirmadas
     * @param  array<int,string>  $cats  categoria escolhida por linha (opcional)
     * @return array{importadas:int, duplicadas:int, ignoradas:int}
     */
    public function confirm(ImportBatch $batch, array $selected, array $cats = [], ?string $costCenter = null): array
    {
        if ($batch->status !== 'previa') {
            throw new BusinessRule('Esta importação já foi concluída ou cancelada.', 'importacao');
        }
        $company = $this->finance->company($batch->company_id);
        $sel = array_flip(array_map('intval', $selected));
        $stats = ['importadas' => 0, 'duplicadas' => 0, 'ignoradas' => 0];

        DB::transaction(function () use ($batch, $company, $sel, $cats, $costCenter, &$stats) {
            $locked = ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->first();
            if ($locked->status !== 'previa') {
                throw new BusinessRule('Esta importação já foi concluída.', 'importacao');
            }
            foreach ($batch->rows as $i => $r) {
                if (! isset($sel[$i])) {
                    $stats['ignoradas']++;

                    continue;
                }
                if (Entry::withPending()->withTrashed()->where('company_id', $company->id)->where('external_ref', $r['id'])->exists()) {
                    $stats['duplicadas']++;   // a mesma transação já entrou (o índice único também impede)

                    continue;
                }
                $this->finance->create($company, ['tipo' => $r['tipo'], 'desc' => $r['desc'], 'valor_cents' => (int) $r['valor_cents'], 'data' => $r['data'],
                    'pago' => true, 'cat' => $cats[$i] ?? $r['cat'] ?? null, 'source' => 'import', 'external_ref' => $r['id'], 'centro' => $costCenter ?: null]);
                $stats['importadas']++;
            }
            $locked->update(['status' => 'importado', 'imported' => $stats['importadas'], 'duplicates' => $stats['duplicadas']]);
        });
        $this->audit->log('importacao.extrato', $batch, ['arquivo' => $batch->filename, 'formato' => $batch->format] + $stats);

        return $stats;
    }
}
