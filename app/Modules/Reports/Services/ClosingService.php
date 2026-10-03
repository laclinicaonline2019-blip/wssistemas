<?php

namespace App\Modules\Reports\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Finance\Models\FinancialTransaction;
use App\Modules\Identity\Models\User;
use App\Modules\Insurance\Models\Guide;
use App\Modules\Payments\Models\PaymentSplit;
use App\Modules\Payments\Services\SplitService;
use App\Modules\Reports\Models\DoctorClosing;
use App\Modules\Scheduling\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Fechamento mensal médico × clínica (Fase 15).
 *
 * Demonstrativo do mês: atendimentos, recebimentos das contas do médico, guias de convênio e a
 * parte do médico (split nativo já recebido direto do gateway/maquininha × repasse interno a
 * pagar pela clínica). Ao fechar, o retrato vira imutável (hash) e, opcionalmente, o repasse
 * interno pendente gera a conta a pagar. O médico confirma ou contesta; contestação → nova versão.
 */
class ClosingService
{
    public function __construct(private readonly SplitService $splits, private readonly AuditLogger $audit) {}

    public function preview(Doctor $doctor, string $period): array
    {
        [$from, $to] = $this->range($period);

        $apps = Appointment::query()->where('doctor_id', $doctor->id)->whereBetween('starts_at', [$from->utc(), $to->utc()])->get(['status', 'payer_type']);
        $group = fn ($s) => match ($s) {
            'completed', 'in_service', 'arrived' => 'attended', 'no_show' => 'no_show', 'cancelled' => 'cancelled', default => 'scheduled'
        };
        $appointments = ['total' => $apps->count()] + collect(['attended', 'no_show', 'cancelled', 'scheduled'])->mapWithKeys(fn ($k) => [$k => $apps->filter(fn ($a) => $group($a->status) === $k)->count()])->all()
            + ['private' => $apps->where('payer_type', 'private')->filter(fn ($a) => $group($a->status) === 'attended')->count(),
                'insurance' => $apps->where('payer_type', 'insurance')->filter(fn ($a) => $group($a->status) === 'attended')->count()];

        $txns = FinancialTransaction::query()->with('receivable:id,doctor_id,payer_type,description')->whereIn('kind', ['receipt', 'reversal'])
            ->whereBetween('occurred_at', [$from->utc(), $to->utc()])->whereHas('receivable', fn ($q) => $q->where('doctor_id', $doctor->id))->orderBy('occurred_at')->get();
        $receipts = $txns->map(fn (FinancialTransaction $t) => [
            'date' => $t->occurred_at->timezone(ReportService::TZ)->format('d/m/Y'), 'description' => (string) $t->receivable->description,
            'method' => $t->methodLabel(), 'payer' => $t->receivable->payer_type === 'insurance' ? 'Convênio' : 'Particular', 'amount' => $t->signedCents(),
        ])->values()->all();

        $guides = Guide::query()->where('doctor_id', $doctor->id)->whereBetween('attendance_date', [$from->toDateString(), $to->toDateString()])->where('status', '!=', 'cancelled')
            ->get(['total_cents', 'paid_cents', 'glosa_cents']);

        $splits = PaymentSplit::query()->where('doctor_id', $doctor->id)->whereBetween('created_at', [$from->utc(), $to->utc()])->orderBy('created_at')->get();
        $sum = fn ($c) => (int) $c->sum('amount_cents');
        $internalPending = $splits->where('mode', 'internal')->where('status', 'pending');

        return [
            'period' => $period, 'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'doctor' => ['id' => $doctor->id, 'name' => $doctor->displayName(), 'crm' => trim($doctor->crm.'/'.$doctor->crm_state, '/')],
            'appointments' => $appointments,
            'receipts' => ['count' => count($receipts), 'total' => array_sum(array_column($receipts, 'amount')), 'lines' => $receipts],
            'insurance' => ['guides' => $guides->count(), 'total' => (int) $guides->sum('total_cents'), 'paid' => (int) $guides->sum('paid_cents'), 'glosa' => (int) $guides->sum('glosa_cents')],
            'split' => [
                'base' => (int) $splits->sum('base_cents'), 'share' => $sum($splits),
                'native' => $sum($splits->where('mode', 'native')), 'internal_settled' => $sum($splits->where('mode', 'internal')->where('status', 'settled')),
                'internal_pending' => $sum($internalPending), 'pending_count' => $internalPending->count(),
                'lines' => $splits->map(fn (PaymentSplit $s) => ['date' => $s->created_at->timezone(ReportService::TZ)->format('d/m/Y'), 'base' => $s->base_cents, 'amount' => $s->amount_cents,
                    'mode' => $s->mode === 'native' ? 'Recebido direto ('.(PaymentSplit::SOURCES[$s->source] ?? 'gateway').')' : 'Repasse da clínica', 'status' => $s->status])->values()->all(),
            ],
        ];
    }

    public function close(User $actor, Doctor $doctor, string $period, bool $settle): DoctorClosing
    {
        [$from, $to] = $this->range($period);
        if ($to->gte(CarbonImmutable::now(ReportService::TZ)->startOfMonth())) {
            throw new BusinessRuleViolation('Só é possível fechar meses já encerrados.', 'closing_open_month');
        }

        return DB::transaction(function () use ($actor, $doctor, $period, $settle, $from, $to) {
            $current = DoctorClosing::query()->where('doctor_id', $doctor->id)->where('period', $period)->where('status', '!=', 'superseded')->lockForUpdate()->first();
            if ($current && $current->status !== 'disputed') {
                throw new BusinessRuleViolation('Este mês já foi fechado para o médico. Só um fechamento contestado pode ser refeito.', 'closing_exists', 409);
            }

            $data = $this->preview($doctor, $period);
            $payable = null;
            if ($settle && $data['split']['internal_pending'] > 0) {
                $payable = $this->splits->settle($actor, $doctor->id, $from->toDateString(), $to->toDateString());
            }
            $data['closed_at'] = now()->toIso8601String();
            $data['closed_by'] = $actor->name;
            $data['payable_id'] = $payable?->id;

            $version = (int) DoctorClosing::query()->where('doctor_id', $doctor->id)->where('period', $period)->max('version') + 1;
            $current?->forceFill(['status' => 'superseded'])->save();
            $closing = DoctorClosing::create([
                'doctor_id' => $doctor->id, 'period' => $period, 'version' => $version, 'status' => 'closed', 'data' => $data, 'hash' => DoctorClosing::hashOf($data),
                'doctor_share_cents' => $data['split']['share'], 'to_pay_cents' => $data['split']['internal_pending'], 'payable_id' => $payable?->id,
                'closed_by' => $actor->id, 'closed_at' => now(),
            ]);
            $this->audit->record('closing.closed', $closing, metadata: ['doctor_id' => $doctor->id, 'period' => $period, 'version' => $version, 'payable_id' => $payable?->id, 'hash' => $closing->hash]);

            return $closing;
        });
    }

    /** O médico (usuário vinculado ao cadastro) confirma ou contesta o demonstrativo. */
    public function respond(User $user, DoctorClosing $closing, bool $confirm, ?string $notes): DoctorClosing
    {
        $doctor = Doctor::query()->withTrashed()->find($closing->doctor_id);
        if (! $doctor || $doctor->user_id !== $user->id) {
            abort(403);
        }
        if ($closing->status !== 'closed') {
            throw new BusinessRuleViolation('Este demonstrativo já foi respondido ou substituído.', 'closing_answered', 409);
        }
        if (! $confirm && trim((string) $notes) === '') {
            throw new BusinessRuleViolation('Explique o que está divergente.', 'closing_dispute_reason');
        }
        $closing->forceFill(['status' => $confirm ? 'confirmed' : 'disputed', 'responded_by' => $user->id, 'responded_at' => now(),
            'response_notes' => $notes ? mb_substr($notes, 0, 1000) : null])->save();
        $this->audit->record($confirm ? 'closing.confirmed' : 'closing.disputed', $closing, metadata: ['period' => $closing->period, 'version' => $closing->version]);

        return $closing;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function range(string $period): array
    {
        if (! preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $period)) {
            throw new BusinessRuleViolation('Mês inválido.', 'closing_period');
        }
        $from = CarbonImmutable::parse($period.'-01', ReportService::TZ)->startOfDay();

        return [$from, $from->endOfMonth()];
    }
}
