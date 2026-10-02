<?php

namespace App\Modules\Insurance\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Core\Support\SequenceGenerator;
use App\Core\Tenancy\TenantContext;
use App\Modules\Finance\Models\FinancialCategory;
use App\Modules\Finance\Models\FinancialTransaction;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Identity\Models\User;
use App\Modules\Insurance\Models\Authorization;
use App\Modules\Insurance\Models\Batch;
use App\Modules\Insurance\Models\Guide;
use App\Modules\Insurance\Models\Insurer;
use App\Modules\Insurance\Tiss\TissMessageBuilder;
use App\Modules\Payments\Services\SplitService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Faturamento do convênio por lote.
 *
 * 1. Montar o lote com guias PRONTAS do mesmo convênio, unidade e tipo (máx. 100 — TISS).
 * 2. Fechar: gera o XML TISS (validado no XSD oficial; inválido não fecha), marca as guias
 *    como faturadas, as autorizações como utilizadas e cria a conta a receber do convênio.
 * 3. Retorno (demonstrativo da operadora): valor pago por guia; a diferença é GLOSA.
 *    O pagamento entra no financeiro (fora do caixa) e gera o repasse de cada médico.
 * 4. Glosa: recurso (fica em aberto), aceitar (baixa do saldo) ou recurso concluído
 *    (valor recuperado entra; o restante é baixado).
 */
class BatchService
{
    public function __construct(
        private readonly TissMessageBuilder $tiss,
        private readonly SequenceGenerator $sequences,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly FinanceService $finance,
        private readonly SplitService $splits,
    ) {}

    /** @param list<string> $guideIds */
    public function create(User $actor, string $insurerId, string $branchId, string $guideType, array $guideIds): Batch
    {
        $insurer = Insurer::query()->findOrFail($insurerId);

        return DB::transaction(function () use ($actor, $insurer, $branchId, $guideType, $guideIds) {
            $guides = Guide::query()->whereIn('id', $guideIds)->lockForUpdate()->get();
            $this->assertGuides($guides, $insurer, $branchId, $guideType, count($guideIds));

            if ($guides->count() > min(100, $insurer->max_guides_per_batch)) {
                throw new BusinessRuleViolation('Máximo de '.min(100, $insurer->max_guides_per_batch).' guias por lote.', 'batch_too_large');
            }

            $batch = Batch::create([
                'branch_id' => $branchId, 'insurer_id' => $insurer->id, 'guide_type' => $guideType,
                'number' => (string) $this->sequences->next($this->context->companyId(), 'insurance_batch'),
                'competence' => CarbonImmutable::now('America/Sao_Paulo')->format('Y-m'), 'created_by' => $actor->id,
            ]);

            Guide::query()->whereIn('id', $guides->pluck('id'))->update(['batch_id' => $batch->id, 'updated_at' => now()]);
            $this->refreshTotals($batch);

            return $batch;
        });
    }

    public function removeGuide(Batch $batch, Guide $guide): void
    {
        if ($batch->status !== 'open' || $guide->batch_id !== $batch->id) {
            throw new BusinessRuleViolation('Só é possível retirar guias de lote aberto.', 'batch_not_open', 409);
        }

        DB::transaction(function () use ($batch, $guide) {
            $guide->forceFill(['batch_id' => null])->save();
            $this->refreshTotals($batch);
        });
    }

    /** Fecha o lote: XML TISS validado, guias faturadas e conta a receber do convênio. */
    public function close(User $actor, Batch $batch): Batch
    {
        return DB::transaction(function () use ($actor, $batch) {
            $b = Batch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($b->status !== 'open') {
                throw new BusinessRuleViolation('Lote já fechado.', 'batch_not_open', 409);
            }

            $guides = Guide::query()->where('batch_id', $b->id)->lockForUpdate()->get();
            if ($guides->isEmpty()) {
                throw new BusinessRuleViolation('Lote sem guias.', 'batch_empty');
            }
            if ($guides->contains(fn (Guide $g) => $g->status !== 'ready')) {
                throw new BusinessRuleViolation('Há guias no lote que não estão prontas.', 'guide_not_ready');
            }
            if ($missing = $b->insurer->tissIssues()) {
                throw new BusinessRuleViolation('Cadastro do convênio sem '.implode(' e ', $missing).'.', 'insurer_tiss_incomplete');
            }

            $this->refreshTotals($b);
            ['xml' => $xml, 'hash' => $hash] = $this->tiss->build($b->fresh());
            $errors = $this->tiss->validate($xml, $b->insurer->tiss_version);

            if ($errors !== []) {
                throw new BusinessRuleViolation('O XML TISS não passou na validação do schema da ANS: '.implode(' | ', array_slice($errors, 0, 5)), 'tiss_schema_invalid');
            }

            $receivable = Receivable::create([
                'branch_id' => $b->branch_id, 'category_id' => $this->category(),
                'description' => mb_substr("Convênio {$b->insurer->name} — lote {$b->number} ({$guides->count()} guias)", 0, 200),
                'amount_cents' => $b->total_cents,
                'due_date' => CarbonImmutable::now('America/Sao_Paulo')->addDays($b->insurer->payment_term_days)->toDateString(),
                'origin' => 'insurance', 'payer_type' => 'insurance', 'created_by' => $actor->id,
            ]);

            $b->forceFill([
                // O banco guarda em UTF-8; o arquivo enviado à operadora volta para ISO-8859-1 (Batch::xmlFile()).
                'status' => 'closed', 'xml' => mb_convert_encoding($xml, 'UTF-8', 'ISO-8859-1'), 'xml_hash' => $hash, 'xml_schema_valid' => true,
                'receivable_id' => $receivable->id, 'closed_at' => now(), 'closed_by' => $actor->id,
            ])->save();

            Guide::query()->whereIn('id', $guides->pluck('id'))->update(['status' => 'billed', 'updated_at' => now()]);
            Authorization::query()->whereIn('id', $guides->pluck('authorization_id')->filter())->where('status', 'authorized')->update(['status' => 'used', 'updated_at' => now()]);

            $this->audit->record('insurance.batch_closed', $b, metadata: ['number' => $b->number, 'guides' => $guides->count(), 'total_cents' => $b->total_cents, 'xml_hash' => $hash]);

            return $b;
        });
    }

    /** Protocolo de recebimento informado pela operadora (após o envio no portal). */
    public function markSent(Batch $batch, string $protocol): Batch
    {
        if (! in_array($batch->status, ['closed', 'partial', 'paid'], true)) {
            throw new BusinessRuleViolation('Feche o lote antes de registrar o envio.', 'batch_not_closed', 409);
        }
        $batch->forceFill(['protocol' => $protocol, 'sent_at' => $batch->sent_at ?? now()])->save();

        return $batch;
    }

    public function cancel(User $actor, Batch $batch, string $reason): Batch
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleViolation('Informe o motivo.', 'reason_required');
        }

        return DB::transaction(function () use ($actor, $batch, $reason) {
            $b = Batch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if (! in_array($b->status, ['open', 'closed'], true) || $b->paid_cents > 0 || Guide::query()->where('batch_id', $b->id)->whereNotIn('status', ['ready', 'billed'])->exists()) {
                throw new BusinessRuleViolation('Lote com retorno registrado não pode ser cancelado.', 'batch_not_cancellable', 409);
            }

            if ($b->receivable_id) {
                $this->finance->cancelReceivable($actor, Receivable::query()->findOrFail($b->receivable_id), 'Lote de convênio cancelado: '.$reason);
            }

            $guideIds = Guide::query()->where('batch_id', $b->id)->pluck('id');
            // As guias voltam a ficar prontas para outro lote (o XML deste lote fica guardado no histórico).
            Guide::query()->whereIn('id', $guideIds)->update(['status' => 'ready', 'batch_id' => null, 'updated_at' => now()]);
            Authorization::query()->whereIn('id', Guide::query()->whereIn('id', $guideIds)->whereNotNull('authorization_id')->pluck('authorization_id'))
                ->where('status', 'used')->update(['status' => 'authorized', 'updated_at' => now()]);

            $b->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => mb_substr($reason, 0, 255)])->save();

            return $b;
        });
    }

    /**
     * Retorno da operadora (demonstrativo de pagamento).
     *
     * @param  array<string, array{paid_cents: int, glosa_code?: ?string, glosa_reason?: ?string}>  $results  por guia
     */
    public function registerReturn(User $actor, Batch $batch, array $results, string $method, string $paidOn): Batch
    {
        if ($results === []) {
            throw new BusinessRuleViolation('Informe o valor pago de ao menos uma guia.', 'return_empty');
        }

        return DB::transaction(function () use ($actor, $batch, $results, $method, $paidOn) {
            $b = Batch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if (! in_array($b->status, ['closed', 'partial'], true)) {
                throw new BusinessRuleViolation('O retorno é registrado em lote fechado.', 'batch_not_closed', 409);
            }

            $guides = Guide::query()->where('batch_id', $b->id)->whereIn('id', array_keys($results))->lockForUpdate()->get()->keyBy('id');
            if ($guides->count() !== count($results)) {
                throw new BusinessRuleViolation('Guia não pertence a este lote.', 'guide_not_in_batch');
            }

            $paidTotal = 0;
            $splitBase = [];

            foreach ($results as $guideId => $r) {
                $g = $guides[$guideId];
                $paid = (int) $r['paid_cents'];

                if ($g->status !== 'billed') {
                    throw new BusinessRuleViolation("Guia {$g->number}: retorno já registrado.", 'guide_already_returned', 409);
                }
                if ($paid < 0 || $paid > $g->total_cents) {
                    throw new BusinessRuleViolation("Guia {$g->number}: valor pago maior que o valor da guia.", 'paid_exceeds_guide');
                }

                $glosa = $g->total_cents - $paid;
                if ($glosa > 0 && mb_strlen(trim((string) ($r['glosa_reason'] ?? ''))) < 3 && empty($r['glosa_code'])) {
                    throw new BusinessRuleViolation("Guia {$g->number}: informe o motivo/código da glosa.", 'glosa_reason_required');
                }

                $g->forceFill([
                    'paid_cents' => $paid, 'glosa_cents' => $glosa,
                    'status' => $glosa === 0 ? 'paid' : ($paid === 0 ? 'denied' : 'partial'),
                    'glosa_status' => $glosa > 0 ? 'pending' : null,
                    'glosa_code' => $glosa > 0 ? ($r['glosa_code'] ?? null) : null,
                    'glosa_reason' => $glosa > 0 ? mb_substr((string) ($r['glosa_reason'] ?? ''), 0, 255) : null,
                ])->save();

                $paidTotal += $paid;
                $splitBase[] = $this->splitRow($g, $paid);
            }

            $this->receiveFromInsurer($actor, $b, $paidTotal, $method, $paidOn, $splitBase);
            $this->refreshReturnTotals($b);
            $this->audit->record('insurance.batch_return', $b, metadata: ['guides' => count($results), 'paid_cents' => $paidTotal]);

            return $b;
        });
    }

    /**
     * Tratamento da glosa da guia.
     *
     * - appeal: registra o recurso (o valor continua a receber);
     * - accept: aceita a glosa (baixa o saldo — não será recebido);
     * - recover: recurso concluído — recebe o valor recuperado e baixa o restante.
     */
    public function resolveGlosa(User $actor, Guide $guide, string $action, array $data = []): Guide
    {
        return DB::transaction(function () use ($actor, $guide, $action, $data) {
            $g = Guide::query()->whereKey($guide->id)->lockForUpdate()->firstOrFail();
            $b = Batch::query()->whereKey($g->batch_id)->lockForUpdate()->firstOrFail();

            if (! in_array($g->glosa_status, ['pending', 'appealed'], true) || $g->glosa_cents <= 0) {
                throw new BusinessRuleViolation('Esta guia não tem glosa em aberto.', 'no_open_glosa', 409);
            }

            if ($action === 'appeal') {
                if (mb_strlen(trim($data['appeal_text'] ?? '')) < 10) {
                    throw new BusinessRuleViolation('Descreva a justificativa do recurso (mínimo 10 caracteres).', 'appeal_text_required');
                }
                $g->forceFill(['glosa_status' => 'appealed', 'appeal_text' => mb_substr($data['appeal_text'], 0, 1000)])->save();
                $this->audit->record('insurance.glosa_appealed', $g, metadata: ['glosa_cents' => $g->glosa_cents]);

                return $g;
            }

            $recovered = $action === 'recover' ? (int) ($data['recovered_cents'] ?? 0) : 0;
            if ($action === 'recover' && ($recovered <= 0 || $recovered > $g->glosa_cents)) {
                throw new BusinessRuleViolation('Valor recuperado deve ser maior que zero e no máximo o valor glosado.', 'invalid_recovered');
            }
            if ($action === 'recover' && $g->glosa_status !== 'appealed') {
                throw new BusinessRuleViolation('Registre o recurso antes de concluir.', 'appeal_required', 409);
            }

            $writeOff = $g->glosa_cents - $recovered;

            if ($recovered > 0) {
                $this->receiveFromInsurer($actor, $b, $recovered, $data['method'] ?? 'bank_transfer', $data['paid_on'] ?? now('America/Sao_Paulo')->toDateString(), [$this->splitRow($g, $recovered)]);
            }
            if ($writeOff > 0) {
                // Baixa do saldo não recebido (glosa definitiva): abatimento na conta do convênio, sem movimentação de dinheiro.
                $this->finance->receive($actor, Receivable::query()->findOrFail($b->receivable_id),
                    ['method' => 'bank_transfer', 'amount_cents' => 0, 'discount_cents' => $writeOff, 'outside_cash' => true], true);
            }

            $g->forceFill([
                'paid_cents' => $g->paid_cents + $recovered, 'glosa_cents' => $writeOff,
                'glosa_status' => $action === 'recover' ? 'recovered' : 'accepted',
                'status' => $writeOff === 0 ? 'paid' : ($g->paid_cents + $recovered === 0 ? 'denied' : 'partial'),
            ])->save();

            $this->refreshReturnTotals($b);
            $this->audit->record('insurance.glosa_'.($action === 'recover' ? 'recovered' : 'accepted'), $g, metadata: ['recovered_cents' => $recovered, 'written_off_cents' => $writeOff]);

            return $g;
        });
    }

    // ------------------------------------------------------------------ internos

    private function receiveFromInsurer(User $actor, Batch $b, int $amount, string $method, string $paidOn, array $splitRows): void
    {
        if ($amount <= 0) {
            return;
        }

        $txn = $this->finance->receive($actor, Receivable::query()->findOrFail($b->receivable_id), [
            'method' => $method, 'amount_cents' => $amount, 'paid_on' => $paidOn, 'outside_cash' => true,
        ]);

        if ($txn instanceof FinancialTransaction) {
            $this->splits->applyForInsurance($txn, $splitRows);
        }
    }

    private function splitRow(Guide $g, int $paid): array
    {
        return [
            'doctor_id' => $g->doctor_id, 'insurer_id' => $g->insurer_id, 'paid_cents' => $paid,
            'service_id' => $g->appointment_id ? DB::table('appointments')->where('id', $g->appointment_id)->value('service_id') : null,
        ];
    }

    private function assertGuides($guides, Insurer $insurer, string $branchId, string $type, int $expected): void
    {
        if ($guides->isEmpty() || $guides->count() !== $expected) {
            throw new BusinessRuleViolation('Selecione guias válidas.', 'guides_required');
        }

        foreach ($guides as $g) {
            if ($g->status !== 'ready' || $g->batch_id !== null) {
                throw new BusinessRuleViolation("Guia {$g->number} não está pronta ou já está em outro lote.", 'guide_not_ready');
            }
            if ($g->insurer_id !== $insurer->id || $g->branch_id !== $branchId || $g->guide_type !== $type) {
                throw new BusinessRuleViolation("Guia {$g->number}: o lote reúne guias do mesmo convênio, unidade e tipo.", 'guide_mismatch');
            }
        }
    }

    private function refreshTotals(Batch $b): void
    {
        $b->forceFill([
            'guides_count' => Guide::query()->where('batch_id', $b->id)->count(),
            'total_cents' => (int) Guide::query()->where('batch_id', $b->id)->sum('total_cents'),
        ])->save();
    }

    private function refreshReturnTotals(Batch $b): void
    {
        $guides = Guide::query()->where('batch_id', $b->id)->get(['status', 'paid_cents', 'glosa_cents', 'glosa_status']);
        $open = $guides->contains(fn ($g) => $g->status === 'billed' || in_array($g->glosa_status, ['pending', 'appealed'], true));

        $b->forceFill([
            'paid_cents' => (int) $guides->sum('paid_cents'), 'glosa_cents' => (int) $guides->sum('glosa_cents'),
            'status' => $open ? 'partial' : 'paid',
        ])->save();
    }

    private function category(): string
    {
        $this->finance->ensureCategories();

        return FinancialCategory::query()->where('type', 'income')->where('name', 'Convênios')->value('id')
            ?? FinancialCategory::create(['type' => 'income', 'name' => 'Convênios', 'is_active' => true])->id;
    }
}
