<?php

namespace App\Modules\Banking\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Core\Support\Format;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankLineMatch;
use App\Modules\Banking\Models\BankStatementLine;
use App\Modules\Finance\Models\FinancialTransaction;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Conciliação: vincula linhas do extrato a lançamentos do livro (que continua imutável).
 *
 * - Sugestões: mesmo valor (com sinal), lançamento não conciliado, data próxima (cartão de crédito
 *   tem janela maior — o banco credita dias depois), bônus quando o NSU/ID aparece no histórico.
 * - Automático: só os casos sem ambiguidade (um único candidato para a linha e a linha única para o candidato).
 * - Manual: um ou vários lançamentos (ex.: repasse do gateway = recebimentos − tarifas) — a soma tem de bater.
 * - Lançar: tarifa/rendimento sem lançamento → cria a conta (a pagar/receber), baixa fora do caixa e concilia.
 */
class Reconciler
{
    public const WINDOW_DAYS = 5;

    public const CARD_WINDOW_DAYS = 35;

    public function __construct(private readonly FinanceService $finance, private readonly AuditLogger $audit) {}

    /** @return Collection<int, array{transaction: FinancialTransaction, score: int, days: int}> */
    public function suggestions(BankStatementLine $line, int $limit = 5): Collection
    {
        $from = $line->posted_on->copy()->subDays(self::CARD_WINDOW_DAYS)->startOfDay();
        $to = $line->posted_on->copy()->addDays(self::WINDOW_DAYS)->endOfDay();

        return $this->candidates()->where('direction', $line->amount_cents > 0 ? 'in' : 'out')->where('amount_cents', abs($line->amount_cents))
            ->whereBetween('occurred_at', [$from->utc(), $to->utc()])->limit(50)->get()
            ->map(function (FinancialTransaction $t) use ($line) {
                $txnDay = CarbonImmutable::parse($t->occurred_at)->timezone('America/Sao_Paulo')->toDateString();
                $days = (int) abs(CarbonImmutable::parse($txnDay)->diffInDays(CarbonImmutable::parse($line->posted_on->toDateString())));
                $card = in_array($t->method, ['credit_card', 'debit_card'], true);
                if (! $card && $days > self::WINDOW_DAYS) {
                    return null;
                }
                $ref = collect([$t->authorization_code, $t->gateway_reference])->filter(fn ($r) => $r && strlen($r) >= 4)
                    ->contains(fn ($r) => str_contains(Format::searchable($line->description.' '.$line->reference), Format::searchable($r)));

                return ['transaction' => $t, 'days' => $days, 'score' => 100 - min(90, $days * ($card ? 2 : 10)) + ($ref ? 30 : 0)];
            })->filter()->sortByDesc('score')->take($limit)->values();
    }

    /** Concilia os casos exatos e sem ambiguidade de uma conta. Retorna quantas linhas. */
    public function autoReconcile(User $actor, BankAccount $account): int
    {
        $pending = BankStatementLine::query()->where('bank_account_id', $account->id)->where('status', 'pending')->orderBy('posted_on')->limit(500)->get();
        $byLine = [];
        $usage = [];
        foreach ($pending as $line) {
            $ids = $this->suggestions($line, 3)->pluck('transaction.id')->all();
            $byLine[$line->id] = $ids;
            foreach ($ids as $id) {
                $usage[$id] = ($usage[$id] ?? 0) + 1;
            }
        }

        $done = 0;
        foreach ($pending as $line) {
            $ids = $byLine[$line->id];
            if (count($ids) === 1 && $usage[$ids[0]] === 1) {
                try {
                    $this->match($actor, $line, $ids, 'auto');
                    $done++;
                } catch (BusinessRuleViolation) {
                    // mudou desde a sugestão: fica para a conciliação manual
                }
            }
        }
        if ($done) {
            $this->audit->record('bank.auto_reconciled', $account, metadata: ['lines' => $done]);
        }

        return $done;
    }

    /** @param  list<string>  $transactionIds */
    public function match(User $actor, BankStatementLine $line, array $transactionIds, string $origin = 'manual'): BankStatementLine
    {
        $transactionIds = array_values(array_unique($transactionIds));
        if ($transactionIds === []) {
            throw new BusinessRuleViolation('Selecione ao menos um lançamento.', 'bank_match_empty');
        }

        return DB::transaction(function () use ($actor, $line, $transactionIds, $origin) {
            $l = BankStatementLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            if ($l->status !== 'pending') {
                throw new BusinessRuleViolation('Esta linha do extrato já foi resolvida.', 'bank_line_resolved', 409);
            }
            $txns = FinancialTransaction::query()->whereIn('id', $transactionIds)->get();
            if ($txns->count() !== count($transactionIds)) {
                throw new BusinessRuleViolation('Lançamento não encontrado.', 'bank_match_invalid', 404);
            }
            $sum = (int) $txns->sum(fn (FinancialTransaction $t) => $t->signedCents());
            if ($sum !== $l->amount_cents) {
                throw new BusinessRuleViolation('A soma dos lançamentos ('.Format::money($sum).') não bate com o extrato ('.Format::money($l->amount_cents).').', 'bank_match_sum');
            }

            try {
                foreach ($txns as $t) {
                    DB::transaction(fn () => BankLineMatch::create(['line_id' => $l->id, 'transaction_id' => $t->id, 'origin' => $origin, 'matched_by' => $actor->id]));
                }
            } catch (QueryException) {
                throw new BusinessRuleViolation('Um dos lançamentos já está conciliado com outra linha do extrato.', 'bank_match_taken', 409);
            }

            $l->forceFill(['status' => 'reconciled', 'resolved_by' => $actor->id, 'resolved_at' => now()])->save();
            $this->audit->record('bank.line_reconciled', $l, metadata: ['transactions' => $transactionIds, 'origin' => $origin, 'amount_cents' => $l->amount_cents]);

            return $l;
        });
    }

    public function ignore(User $actor, BankStatementLine $line, string $reason): BankStatementLine
    {
        if ($line->status !== 'pending') {
            throw new BusinessRuleViolation('Esta linha do extrato já foi resolvida.', 'bank_line_resolved', 409);
        }
        $line->forceFill(['status' => 'ignored', 'notes' => mb_substr($reason, 0, 500), 'resolved_by' => $actor->id, 'resolved_at' => now()])->save();
        $this->audit->record('bank.line_ignored', $line, metadata: ['reason' => mb_substr($reason, 0, 200)]);

        return $line;
    }

    /** Volta a linha para "a conciliar"; os vínculos ficam no histórico (undone_at). */
    public function undo(User $actor, BankStatementLine $line): BankStatementLine
    {
        return DB::transaction(function () use ($actor, $line) {
            BankLineMatch::query()->where('line_id', $line->id)->whereNull('undone_at')->update(['undone_at' => now(), 'undone_by' => $actor->id, 'updated_at' => now()]);
            $line->forceFill(['status' => 'pending', 'resolved_by' => null, 'resolved_at' => null, 'notes' => null])->save();
            $this->audit->record('bank.line_reopened', $line);

            return $line;
        });
    }

    /**
     * Lançamento que só existe no banco (tarifa, juros, rendimento, transferência recebida):
     * cria a conta a pagar/receber, dá baixa FORA do caixa, na data do extrato, e concilia.
     */
    public function createEntry(User $actor, BankStatementLine $line, array $data): BankStatementLine
    {
        if ($line->status !== 'pending') {
            throw new BusinessRuleViolation('Esta linha do extrato já foi resolvida.', 'bank_line_resolved', 409);
        }
        $account = BankAccount::query()->findOrFail($line->bank_account_id);
        $branchId = $data['branch_id'] ?? $account->branch_id;
        $amount = abs($line->amount_cents);
        $paidOn = $line->posted_on->toDateString();

        return DB::transaction(function () use ($actor, $line, $data, $branchId, $amount, $paidOn) {
            if ($line->amount_cents < 0) {
                $payable = $this->finance->createPayable($actor, ['branch_id' => $branchId, 'category_id' => $data['category_id'], 'supplier' => $data['counterparty'],
                    'description' => $data['description'], 'amount_cents' => $amount, 'due_date' => $paidOn, 'notes' => 'Criado na conciliação bancária'])->first();
                $txn = $this->finance->pay($actor, $payable, ['amount_cents' => $amount, 'method' => $data['method'], 'paid_on' => $paidOn, 'outside_cash' => true]);
            } else {
                $receivable = $this->finance->createReceivable($actor, ['branch_id' => $branchId ?? $this->fallbackBranch(), 'category_id' => $data['category_id'],
                    'description' => $data['description'], 'amount_cents' => $amount, 'due_date' => $paidOn, 'notes' => 'Criado na conciliação bancária ('.$data['counterparty'].')']);
                $txn = $this->finance->receive($actor, $receivable, ['amount_cents' => $amount, 'method' => $data['method'], 'paid_on' => $paidOn, 'outside_cash' => true]);
            }

            return $this->match($actor, $line, [$txn->id], 'created');
        });
    }

    /** Lançamentos ainda não conciliados (exceto dinheiro do caixa, que não passa pelo banco). */
    public function candidates()
    {
        return FinancialTransaction::query()->where('method', '!=', 'cash')
            ->whereNotIn('id', BankLineMatch::query()->whereNull('undone_at')->select('transaction_id'));
    }

    private function fallbackBranch(): string
    {
        return Branch::query()->orderByDesc('is_headquarters')->value('id');
    }
}
