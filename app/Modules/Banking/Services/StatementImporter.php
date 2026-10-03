<?php

namespace App\Modules\Banking\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Security\FileScanner;
use App\Core\Support\BusinessRuleViolation;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatement;
use App\Modules\Banking\Models\BankStatementLine;
use App\Modules\Banking\Parsers\CsvParser;
use App\Modules\Banking\Parsers\OfxParser;
use App\Modules\Banking\Parsers\ParsedStatement;
use App\Modules\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Grava um extrato sem duplicar: cada lançamento tem uma chave (FITID do banco, ID do Open Finance
 * ou hash de data + valor + descrição + ordem no arquivo). Reimportar o mesmo arquivo — ou um
 * período que se sobrepõe — só acrescenta o que é novo.
 */
class StatementImporter
{
    public function __construct(private readonly OfxParser $ofx, private readonly CsvParser $csv, private readonly AuditLogger $audit) {}

    public function importFile(?User $actor, BankAccount $account, string $content, string $filename): BankStatement
    {
        if (strlen($content) > 5 * 1048576) {
            throw new BusinessRuleViolation('Arquivo maior que 5 MB.', 'statement_size');
        }
        app(FileScanner::class)->assertSafe($content, 'text/plain', 'bank_statement');
        $isOfx = (bool) preg_match('/<OFX>|OFXHEADER/i', substr($content, 0, 4000));
        $parsed = $isOfx ? $this->ofx->parse($content) : $this->csv->parse($content);

        return $this->store($actor, $account, $parsed, $isOfx ? 'ofx' : 'csv', mb_substr($filename, 0, 191), hash('sha256', $content));
    }

    public function store(?User $actor, BankAccount $account, ParsedStatement $parsed, string $source, ?string $filename = null, ?string $sha = null): BankStatement
    {
        if (! $account->is_active) {
            throw new BusinessRuleViolation('Conta bancária desativada.', 'bank_account_inactive');
        }

        return DB::transaction(function () use ($actor, $account, $parsed, $source, $filename, $sha) {
            $statement = BankStatement::create([
                'bank_account_id' => $account->id, 'source' => $source, 'filename' => $filename, 'sha256' => $sha,
                'period_start' => $parsed->periodStart, 'period_end' => $parsed->periodEnd, 'balance_cents' => $parsed->balanceCents,
                'balance_date' => $parsed->balanceDate, 'lines_total' => count($parsed->lines), 'imported_by' => $actor?->id,
            ]);

            $new = 0;
            $seen = [];
            foreach ($parsed->lines as $l) {
                if ($l['reference'] && $source !== 'csv') {
                    $key = 'id:'.mb_substr($l['reference'], 0, 100);
                } else {
                    $base = $l['date'].'|'.$l['amount_cents'].'|'.mb_strtolower(trim($l['description'])).'|'.($l['reference'] ?? '');
                    $seen[$base] = ($seen[$base] ?? 0) + 1; // lançamentos idênticos no mesmo dia continuam distintos
                    $key = 'h:'.sha1($base.'#'.$seen[$base]);
                }
                if (BankStatementLine::query()->where('bank_account_id', $account->id)->where('dedupe_key', $key)->exists()) {
                    continue;
                }
                try {
                    DB::transaction(fn () => BankStatementLine::create([
                        'bank_account_id' => $account->id, 'statement_id' => $statement->id, 'dedupe_key' => $key, 'posted_on' => $l['date'],
                        'amount_cents' => $l['amount_cents'], 'description' => $l['description'], 'reference' => $l['reference'] ? mb_substr($l['reference'], 0, 100) : null,
                    ]));
                    $new++;
                } catch (QueryException) {
                    // importação simultânea do mesmo lançamento: já existe
                }
            }

            $statement->forceFill(['lines_new' => $new])->save();
            $this->audit->record('bank.statement_imported', $statement, metadata: ['bank_account_id' => $account->id, 'source' => $source, 'lines' => count($parsed->lines), 'new' => $new]);

            return $statement;
        });
    }
}
