<?php

namespace App\Modules\Insurance\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Core\Support\Format;
use App\Core\Support\SequenceGenerator;
use App\Core\Tenancy\TenantContext;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Identity\Models\User;
use App\Modules\Insurance\Models\Authorization;
use App\Modules\Insurance\Models\Guide;
use App\Modules\Insurance\Models\GuideItem;
use App\Modules\Insurance\Models\Procedure;
use App\Modules\Patients\Models\PatientInsurance;
use App\Modules\Scheduling\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Guias de atendimento do convênio e autorizações prévias.
 *
 * - Agendamento por convênio gera a guia na CHEGADA do paciente (uma vez), já com o
 *   procedimento do tipo de atendimento e o valor da tabela vigente do convênio/plano.
 * - Coparticipação da tabela vira conta a receber PARTICULAR do paciente (atendimento misto).
 * - "Pronta para faturar" só com: atendimento realizado, carteirinha válida na data,
 *   itens com valor, autorização válida para itens que exigem, dados TISS do médico.
 */
class GuideService
{
    public const DEFAULT_CBO = '225125'; // Médico clínico

    public function __construct(
        private readonly InsuranceCatalog $catalog,
        private readonly SequenceGenerator $sequences,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly FinanceService $finance,
    ) {}

    // ------------------------------------------------------------------ autorizações

    public function requestAuthorization(User $actor, array $data): Authorization
    {
        $insurance = PatientInsurance::query()->where('patient_id', $data['patient_id'])->whereKey($data['patient_insurance_id'])->first()
            ?? throw new BusinessRuleViolation('Carteirinha não pertence ao paciente.', 'insurance_mismatch');
        $insurer = $this->catalog->assertCoverage($insurance, $data['doctor_id'] ?? null, now('America/Sao_Paulo')->toDateString());

        $auth = Authorization::create([
            'branch_id' => $data['branch_id'], 'patient_id' => $insurance->patient_id, 'patient_insurance_id' => $insurance->id,
            'insurer_id' => $insurer->id, 'procedure_id' => $data['procedure_id'], 'doctor_id' => $data['doctor_id'] ?? null,
            'quantity' => (int) ($data['quantity'] ?? 1), 'notes' => $data['notes'] ?? null, 'created_by' => $actor->id,
            'operator_guide_number' => $data['operator_guide_number'] ?? null,
        ]);

        return $auth;
    }

    /** Registra a resposta da operadora (autorizada com senha/validade, ou negada com motivo). */
    public function decideAuthorization(User $actor, Authorization $auth, array $data): Authorization
    {
        if ($auth->status !== 'requested') {
            throw new BusinessRuleViolation('Autorização já respondida ('.mb_strtolower($auth->statusLabel()).').', 'authorization_decided', 409);
        }

        if ($data['decision'] === 'authorized') {
            if (empty($data['password']) && empty($data['operator_guide_number'])) {
                throw new BusinessRuleViolation('Informe a senha ou o nº da guia na operadora.', 'authorization_password_required');
            }
            $auth->fill([
                'status' => 'authorized', 'password' => $data['password'] ?? null, 'operator_guide_number' => $data['operator_guide_number'] ?? $auth->operator_guide_number,
                'authorized_on' => $data['authorized_on'] ?? now('America/Sao_Paulo')->toDateString(), 'valid_until' => $data['valid_until'] ?? null,
            ]);
        } else {
            if (mb_strlen(trim($data['denial_reason'] ?? '')) < 3) {
                throw new BusinessRuleViolation('Informe o motivo da negativa.', 'denial_reason_required');
            }
            $auth->fill(['status' => 'denied', 'denial_reason' => $data['denial_reason']]);
        }

        $auth->forceFill(['decided_by' => $actor->id, 'decided_at' => now()])->save();

        return $auth;
    }

    public function cancelAuthorization(Authorization $auth): Authorization
    {
        if (! in_array($auth->status, ['requested', 'authorized'], true)) {
            throw new BusinessRuleViolation('Esta autorização não pode ser cancelada.', 'authorization_not_cancellable', 409);
        }
        if (Guide::query()->where('authorization_id', $auth->id)->whereNotIn('status', ['cancelled'])->exists()) {
            throw new BusinessRuleViolation('Autorização vinculada a uma guia — retire da guia antes.', 'authorization_in_use', 409);
        }
        $auth->update(['status' => 'cancelled']);

        return $auth;
    }

    // ------------------------------------------------------------------ guias

    /** Guia do agendamento por convênio (idempotente). Retorna null se o agendamento não é de convênio. */
    public function forAppointment(Appointment $appointment, ?User $actor = null): ?Guide
    {
        if ($appointment->payer_type !== 'insurance' || ! $appointment->patient_insurance_id) {
            return null;
        }
        if ($existing = Guide::query()->where('appointment_id', $appointment->id)->first()) {
            return $existing;
        }

        $appointment->loadMissing(['service', 'branch:id,timezone']);
        $tz = $appointment->branch->timezone ?: 'America/Sao_Paulo';
        $date = $appointment->starts_at->timezone($tz)->toDateString();
        $insurance = PatientInsurance::query()->findOrFail($appointment->patient_insurance_id);
        $insurer = $this->catalog->assertCoverage($insurance, $appointment->doctor_id, $date);
        $procedure = $appointment->service?->procedure_id ? Procedure::query()->find($appointment->service->procedure_id) : null;

        try {
            return DB::transaction(function () use ($appointment, $actor, $insurance, $insurer, $procedure, $date) {
                $guide = $this->newGuide($actor, [
                    'branch_id' => $appointment->branch_id, 'insurer_id' => $insurer->id, 'insurance' => $insurance,
                    'appointment_id' => $appointment->id, 'doctor_id' => $appointment->doctor_id, 'attendance_date' => $date,
                    'guide_type' => ! $procedure || $procedure->kind === 'consultation' ? 'consulta' : 'sp_sadt',
                    'consultation_type' => $appointment->service?->is_return ? '2' : '1',
                    'attendance_type' => $procedure ? Procedure::KINDS[$procedure->kind]['attendance_type'] : '04',
                ]);

                if ($procedure && $this->catalog->priceFor($insurer->id, $insurance->plan_id, $procedure->id, $date)) {
                    $item = $this->addItem($actor, $guide, $procedure->id, 1, $date);

                    // Já existe autorização da operadora para este procedimento? Vincula.
                    if ($item->requires_authorization) {
                        $guide->forceFill(['authorization_id' => Authorization::query()->where('patient_insurance_id', $insurance->id)
                            ->where('procedure_id', $procedure->id)->where('status', 'authorized')
                            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $date))
                            ->where(fn ($q) => $q->whereNull('doctor_id')->orWhere('doctor_id', $appointment->doctor_id))
                            ->whereNotIn('id', Guide::query()->whereNotNull('authorization_id')->where('status', '!=', 'cancelled')->select('authorization_id'))
                            ->orderBy('created_at')->value('id')])->save();
                    }
                }

                return $guide->fresh();
            });
        } catch (QueryException $e) {
            // Corrida entre duas chegadas: UNIQUE(company_id, appointment_id) garante uma guia só.
            return Guide::query()->where('appointment_id', $appointment->id)->first() ?? throw $e;
        }
    }

    /** Guia avulsa (sem agendamento — ex.: exame feito na clínica). */
    public function createManual(User $actor, array $data): Guide
    {
        $insurance = PatientInsurance::query()->where('patient_id', $data['patient_id'])->whereKey($data['patient_insurance_id'])->first()
            ?? throw new BusinessRuleViolation('Carteirinha não pertence ao paciente.', 'insurance_mismatch');
        $insurer = $this->catalog->assertCoverage($insurance, $data['doctor_id'], $data['attendance_date']);

        if ($data['attendance_date'] > now('America/Sao_Paulo')->toDateString()) {
            throw new BusinessRuleViolation('A guia é do atendimento já realizado — data no futuro.', 'invalid_date');
        }

        return DB::transaction(fn () => $this->newGuide($actor, [
            'branch_id' => $data['branch_id'], 'insurer_id' => $insurer->id, 'insurance' => $insurance, 'appointment_id' => null,
            'doctor_id' => $data['doctor_id'], 'attendance_date' => $data['attendance_date'], 'guide_type' => $data['guide_type'],
            'consultation_type' => $data['consultation_type'] ?? '1', 'attendance_type' => $data['attendance_type'] ?? ($data['guide_type'] === 'consulta' ? '04' : '23'),
        ]));
    }

    public function update(Guide $guide, array $data): Guide
    {
        $this->assertEditable($guide);

        if (! empty($data['authorization_id'])) {
            $auth = Authorization::query()->find($data['authorization_id']);
            if (! $auth || $auth->patient_insurance_id !== $guide->patient_insurance_id) {
                throw new BusinessRuleViolation('A autorização não é desta carteirinha.', 'authorization_mismatch');
            }
        }

        $guide->fill(array_intersect_key($data, array_flip([
            'operator_guide_number', 'authorization_id', 'consultation_type', 'attendance_type', 'accident_indicator', 'character',
            'clinical_indication', 'observation', 'cbo_code',
        ])));
        if ($guide->isDirty() && $guide->status === 'ready') {
            $guide->status = 'draft'; // mudou depois de conferida: confere de novo
        }
        $guide->save();

        return $guide;
    }

    public function addItem(?User $actor, Guide $guide, string $procedureId, int $quantity, string $executionDate): GuideItem
    {
        $this->assertEditable($guide);
        $procedure = Procedure::query()->where('is_active', true)->find($procedureId)
            ?? throw new BusinessRuleViolation('Procedimento não encontrado ou inativo.', 'procedure_not_found');

        if ($guide->guide_type === 'consulta') {
            if ($procedure->kind !== 'consultation' || $quantity !== 1 || $guide->items()->exists()) {
                throw new BusinessRuleViolation('A guia de consulta tem um único procedimento de consulta (quantidade 1). Exames e procedimentos vão em guia SP/SADT.', 'consultation_single_item');
            }
        }
        if ($quantity < 1 || $quantity > 999) {
            throw new BusinessRuleViolation('Quantidade inválida.', 'invalid_quantity');
        }

        $insurance = PatientInsurance::query()->findOrFail($guide->patient_insurance_id);
        $price = $this->catalog->priceFor($guide->insurer_id, $guide->plan_id ?? $insurance->plan_id, $procedure->id, $executionDate)
            ?? throw new BusinessRuleViolation("Sem valor vigente para {$procedure->code} na tabela deste convênio/plano.", 'price_not_found');

        return DB::transaction(function () use ($actor, $guide, $procedure, $quantity, $executionDate, $price) {
            $item = GuideItem::create([
                'guide_id' => $guide->id, 'procedure_id' => $procedure->id, 'table_code' => $procedure->table_code, 'code' => $procedure->code,
                'description' => $procedure->name, 'execution_date' => $executionDate, 'quantity' => $quantity,
                'unit_cents' => $price->price_cents, 'total_cents' => $price->price_cents * $quantity,
                'requires_authorization' => $price->requires_authorization,
            ]);

            $this->recalculate($guide);
            $this->applyCopay($actor, $guide, $price->copayOf($item->total_cents), $item->description);

            return $item;
        });
    }

    public function removeItem(Guide $guide, GuideItem $item): void
    {
        $this->assertEditable($guide);
        abort_unless($item->guide_id === $guide->id, 404);

        DB::transaction(function () use ($guide, $item) {
            $item->delete();
            $this->recalculate($guide);
        });
    }

    /** Pendências que impedem marcar a guia como pronta. @return list<string> */
    public function issues(Guide $guide): array
    {
        $guide->loadMissing(['items', 'doctor', 'insurer', 'appointment', 'authorization']);
        $issues = [];
        $date = $guide->attendance_date->toDateString();

        if ($guide->items->isEmpty()) {
            $issues[] = 'Inclua o procedimento realizado (com valor na tabela do convênio).';
        }
        if ($guide->appointment && (! $guide->appointment->arrived_at || in_array($guide->appointment->status, ['cancelled', 'no_show'], true))) {
            $issues[] = 'O atendimento não foi realizado (sem chegada registrada).';
        }
        if (mb_strlen($guide->card_number) > 20 || $guide->card_number === '') {
            $issues[] = 'Nº da carteirinha inválido para o TISS (até 20 caracteres).';
        }
        if ($guide->card_valid_until && $guide->card_valid_until->toDateString() < $date) {
            $issues[] = 'Carteirinha vencida na data do atendimento.';
        }
        if ($guide->items->contains('requires_authorization', true)) {
            $auth = $guide->authorization;
            $needed = $guide->items->where('requires_authorization', true)->pluck('procedure_id')->unique();
            if (! $auth) {
                $issues[] = 'Procedimento exige autorização prévia: vincule a autorização (senha) da operadora.';
            } elseif (! $auth->isUsableOn($date)) {
                $issues[] = 'A autorização vinculada não está autorizada ou venceu antes do atendimento.';
            } elseif (! $needed->contains($auth->procedure_id)) {
                $issues[] = 'A autorização vinculada é de outro procedimento.';
            }
        }
        if (! preg_match('/^\d{6}$/', $guide->cbo_code)) {
            $issues[] = 'CBO do médico inválido (6 dígitos).';
        }
        if (! preg_match('/\d/', (string) $guide->doctor->crm)) {
            $issues[] = 'CRM do médico inválido.';
        }
        foreach ($guide->insurer->tissIssues() as $missing) {
            $issues[] = "Cadastro do convênio sem {$missing}.";
        }

        return $issues;
    }

    public function markReady(Guide $guide): Guide
    {
        $this->assertEditable($guide);

        if ($issues = $this->issues($guide)) {
            throw new BusinessRuleViolation('Guia com pendências: '.implode(' ', $issues), 'guide_incomplete');
        }

        $guide->forceFill(['status' => 'ready'])->save();

        return $guide;
    }

    public function backToDraft(Guide $guide): Guide
    {
        $this->assertEditable($guide);
        $guide->forceFill(['status' => 'draft'])->save();

        return $guide;
    }

    public function cancel(User $actor, Guide $guide, string $reason): Guide
    {
        $this->assertEditable($guide);

        DB::transaction(function () use ($actor, $guide, $reason) {
            $guide->forceFill(['status' => 'cancelled', 'cancel_reason' => mb_substr($reason, 0, 255)])->save();

            if ($guide->copay_receivable_id) {
                $r = Receivable::query()->find($guide->copay_receivable_id);
                if ($r && $r->status === 'open' && $r->paid_cents === 0) {
                    $this->finance->cancelReceivable($actor, $r, 'Guia de convênio cancelada: '.$reason);
                }
            }
        });

        return $guide;
    }

    /** Atendimento misto: cobra do paciente, como particular, um valor não coberto pelo convênio. */
    public function chargePatient(User $actor, Guide $guide, string $description, int $amountCents): Receivable
    {
        if ($guide->status === 'cancelled') {
            throw new BusinessRuleViolation('Guia cancelada.', 'guide_cancelled', 409);
        }
        if ($amountCents <= 0) {
            throw new BusinessRuleViolation('Informe o valor.', 'invalid_amount');
        }

        return $this->privateReceivable($actor, $guide, mb_substr($description, 0, 120), $amountCents);
    }

    // ------------------------------------------------------------------ internos

    private function newGuide(?User $actor, array $d): Guide
    {
        /** @var PatientInsurance $insurance */
        $insurance = $d['insurance'];

        return Guide::create([
            'branch_id' => $d['branch_id'], 'insurer_id' => $d['insurer_id'], 'plan_id' => $insurance->plan_id,
            'patient_id' => $insurance->patient_id, 'patient_insurance_id' => $insurance->id, 'appointment_id' => $d['appointment_id'],
            'doctor_id' => $d['doctor_id'], 'guide_type' => $d['guide_type'],
            'number' => str_pad((string) $this->sequences->next($this->context->companyId(), 'insurance_guide'), 8, '0', STR_PAD_LEFT),
            'card_number' => mb_substr(preg_replace('/\s+/', '', $insurance->card_number), 0, 20), 'card_valid_until' => $insurance->valid_until,
            'cbo_code' => $this->cboFor($d['doctor_id']), 'attendance_date' => $d['attendance_date'],
            'consultation_type' => $d['consultation_type'], 'attendance_type' => $d['attendance_type'],
            'created_by' => $actor?->id,
        ]);
    }

    /** CBO da especialidade do médico (primeira com CBO cadastrado) ou médico clínico. */
    private function cboFor(string $doctorId): string
    {
        $cbo = DB::table('doctor_specialty as ds')->join('specialties as s', 's.id', '=', 'ds.specialty_id')
            ->where('ds.doctor_id', $doctorId)->whereNotNull('s.cbo_code')->orderBy('s.name')->value('s.cbo_code');

        $cbo = preg_replace('/\D/', '', (string) $cbo);

        return strlen($cbo) === 6 ? $cbo : self::DEFAULT_CBO;
    }

    private function recalculate(Guide $guide): void
    {
        $guide->forceFill(['total_cents' => (int) GuideItem::query()->where('guide_id', $guide->id)->sum('total_cents')]);
        if ($guide->isDirty('total_cents') && $guide->status === 'ready') {
            $guide->status = 'draft';
        }
        $guide->save();
    }

    private function applyCopay(?User $actor, Guide $guide, int $copay, string $what): void
    {
        if ($copay <= 0) {
            return;
        }

        $receivable = $this->privateReceivable($actor, $guide, 'Coparticipação — '.mb_substr($what, 0, 90), $copay);
        if (! $guide->copay_receivable_id) {
            $guide->forceFill(['copay_receivable_id' => $receivable->id])->save();
        }
    }

    private function privateReceivable(?User $actor, Guide $guide, string $description, int $amount): Receivable
    {
        $doctor = Doctor::query()->find($guide->doctor_id);

        $receivable = Receivable::create([
            'branch_id' => $guide->branch_id, 'category_id' => $this->finance->defaultCategory('income', 'Consultas'),
            'patient_id' => $guide->patient_id, 'doctor_id' => $guide->doctor_id,
            'description' => mb_substr($description.' — guia '.$guide->number.' — '.$doctor?->displayName(), 0, 200),
            'amount_cents' => $amount, 'due_date' => CarbonImmutable::now('America/Sao_Paulo')->toDateString(),
            'origin' => 'insurance_copay', 'payer_type' => 'private', 'created_by' => $actor?->id, 'insurance_guide_id' => $guide->id,
        ]);
        $this->audit->record('insurance.patient_charged', $guide, metadata: ['receivable_id' => $receivable->id, 'amount_cents' => $amount, 'amount' => Format::money($amount)]);

        return $receivable;
    }

    private function assertEditable(Guide $guide): void
    {
        if (! $guide->isEditable()) {
            throw new BusinessRuleViolation($guide->batch_id ? 'Guia em lote de faturamento — retire do lote para alterar.' : 'Guia '.mb_strtolower($guide->statusLabel()).' não pode ser alterada.', 'guide_locked', 409);
        }
    }
}
