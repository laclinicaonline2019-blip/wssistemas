<?php

namespace App\Modules\Clinical\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Core\Tenancy\TenantContext;
use App\Modules\Clinical\Models\CidCode;
use App\Modules\Clinical\Models\Encounter;
use App\Modules\Clinical\Models\EncounterDiagnosis;
use App\Modules\Clinical\Models\EncounterVersion;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Branch;
use App\Modules\Patients\Models\Patient;
use App\Modules\Queue\Models\QueueTicket;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Support\Facades\DB;

/**
 * Prontuário eletrônico.
 *
 * - Rascunho (autosave) mutável, com controle otimista de revisão para não
 *   sobrescrever edições de outra aba/dispositivo.
 * - Finalização: gera versão 1 imutável (HMAC encadeado); diagnósticos com cópia
 *   do CID. Depois disso, qualquer correção é um ADENDO (nova versão completa +
 *   justificativa) — nada é apagado ou sobrescrito (CFM/Lei 13.787/2018).
 * - Somente o médico responsável edita/finaliza/adenda. Conteúdo clínico nunca
 *   vai para a trilha de auditoria (apenas o fato, a versão e o hash).
 */
class EncounterService
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    private const MAX_TEXT = 20000;

    public function __construct(
        private readonly TenantContext $context,
        private readonly TriageService $triages,
        private readonly AuditLogger $audit,
    ) {}

    public function doctorFor(User $user): ?Doctor
    {
        return Doctor::query()->where('user_id', $user->id)->where('status', 'active')->first();
    }

    /** Inicia (ou retoma) o atendimento de um agendamento. */
    public function startFromAppointment(User $actor, Appointment $appointment): Encounter
    {
        $doctor = $this->requireDoctor($actor);

        if ($appointment->doctor_id !== $doctor->id) {
            throw new BusinessRuleViolation('Este agendamento pertence a outro médico.', 'not_your_patient', 403);
        }

        if ($existing = Encounter::query()->where('appointment_id', $appointment->id)->first()) {
            return $existing;
        }

        if (! in_array($appointment->status, ['scheduled', 'confirmed', 'arrived', 'in_service'], true)) {
            throw new BusinessRuleViolation('Agendamento '.mb_strtolower($appointment->statusLabel()).' não pode ser atendido.', 'invalid_status');
        }

        return DB::transaction(function () use ($actor, $appointment, $doctor) {
            $encounter = $this->create($actor, $doctor, $appointment->patient, $appointment->branch_id, $appointment);

            if ($appointment->status !== 'in_service') {
                $appointment->forceFill(['status' => 'in_service', 'started_at' => now(), 'arrived_at' => $appointment->arrived_at ?? now()])->save();
            }

            QueueTicket::query()->where('appointment_id', $appointment->id)->whereIn('status', ['waiting', 'called'])
                ->each(fn (QueueTicket $t) => $t->forceFill(['status' => 'in_service', 'started_at' => now()])->save());

            return $encounter;
        });
    }

    /** Atendimento sem agendamento (encaixe de urgência, demanda espontânea). */
    public function startWalkIn(User $actor, Patient $patient, string $branchId): Encounter
    {
        $doctor = $this->requireDoctor($actor);

        if (! Branch::query()->accessible($this->context->allowedBranchIds())->whereKey($branchId)->exists()) {
            throw new BusinessRuleViolation('Unidade inválida.', 'invalid_branch');
        }

        return $this->create($actor, $doctor, $patient, $branchId, null);
    }

    /**
     * Autosave do rascunho. $revision deve ser a última revisão conhecida pelo cliente.
     *
     * @return array{revision: int, saved_at: string}
     */
    public function saveDraft(User $actor, Encounter $encounter, array $data, int $revision): array
    {
        $this->ensureAuthor($actor, $encounter);

        if (! $encounter->isDraft()) {
            throw new BusinessRuleViolation('Atendimento finalizado: use um adendo.', 'already_finalized', 409);
        }

        $clean = $this->sanitize($data);

        // Atualização condicional: só grava se ninguém salvou depois da revisão do cliente.
        $updated = Encounter::query()->whereKey($encounter->id)->where('draft_revision', $revision)->where('status', 'draft')->update([
            'draft_data' => json_encode($clean, JSON_UNESCAPED_UNICODE),
            'draft_revision' => $revision + 1,
            'draft_saved_at' => now(),
            'updated_at' => now(),
        ]);

        if ($updated === 0) {
            throw new BusinessRuleViolation('Este atendimento foi alterado em outra janela. Recarregue a página para não perder informações.', 'stale_draft', 409);
        }

        return ['revision' => $revision + 1, 'saved_at' => now()->toIso8601String()];
    }

    public function finalize(User $actor, Encounter $encounter, ?array $data = null, ?int $revision = null): Encounter
    {
        $this->ensureAuthor($actor, $encounter);

        return DB::transaction(function () use ($actor, $encounter, $data, $revision) {
            $locked = Encounter::query()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new BusinessRuleViolation('Atendimento já finalizado.', 'already_finalized', 409);
            }

            if ($data !== null) {
                if ($revision !== null && $revision !== $locked->draft_revision) {
                    throw new BusinessRuleViolation('Este atendimento foi alterado em outra janela. Recarregue a página.', 'stale_draft', 409);
                }
                $content = $this->sanitize($data);
            } else {
                $content = $locked->draft_data ?? [];
            }

            if (trim($content['chief_complaint'] ?? '') === '' || trim($content['conduct'] ?? '') === '') {
                throw new BusinessRuleViolation('Para finalizar, preencha ao menos a queixa principal e a conduta.', 'incomplete_record');
            }

            $version = $this->appendVersion($actor, $locked, 'original', $content, null);

            $locked->forceFill(['status' => 'finalized', 'finalized_at' => now(), 'draft_data' => null, 'current_version' => $version->version])->save();

            if ($locked->appointment && $locked->appointment->canTransitionTo('completed')) {
                $locked->appointment->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
            }

            QueueTicket::query()->where('appointment_id', $locked->appointment_id)->whereIn('status', ['called', 'in_service'])
                ->each(fn (QueueTicket $t) => $t->forceFill(['status' => 'done', 'finished_at' => now()])->save());

            $this->audit->record('medical_record.finalized', $locked, metadata: ['version' => $version->version, 'hash' => $version->hash, 'patient_id' => $locked->patient_id]);

            return $locked->fresh(['latestVersion']);
        });
    }

    /** Adendo: nova versão completa com justificativa; a anterior permanece intacta. */
    public function addendum(User $actor, Encounter $encounter, array $data, string $reason): EncounterVersion
    {
        $this->ensureAuthor($actor, $encounter);

        if ($encounter->isDraft()) {
            throw new BusinessRuleViolation('O atendimento ainda está em rascunho — edite e finalize.', 'still_draft');
        }

        if (mb_strlen(trim($reason)) < 10) {
            throw new BusinessRuleViolation('Descreva a justificativa do adendo (mínimo 10 caracteres).', 'reason_required');
        }

        return DB::transaction(function () use ($actor, $encounter, $data, $reason) {
            $locked = Encounter::query()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();
            $version = $this->appendVersion($actor, $locked, 'addendum', $this->sanitize($data), trim($reason));
            $locked->forceFill(['current_version' => $version->version])->save();

            $this->audit->record('medical_record.addendum', $locked, metadata: ['version' => $version->version, 'hash' => $version->hash, 'patient_id' => $locked->patient_id]);

            return $version;
        });
    }

    /** Registro de acesso ao prontuário (LGPD / sigilo médico). */
    public function recordView(Encounter $encounter, string $channel): void
    {
        $this->audit->record('medical_record.viewed', $encounter, metadata: ['patient_id' => $encounter->patient_id, 'channel' => $channel]);
    }

    /**
     * Verifica a integridade das versões (encadeamento + HMAC).
     *
     * @return array{ok: bool, versions: int, broken_at: int|null}
     */
    public function verify(Encounter $encounter): array
    {
        $prev = self::GENESIS;

        foreach ($encounter->versions()->get() as $version) {
            if ($version->prev_hash !== $prev || ! hash_equals($this->hash($version->getAttributes(), $prev), $version->hash)) {
                return ['ok' => false, 'versions' => $version->version, 'broken_at' => $version->version];
            }
            $prev = $version->hash;
        }

        return ['ok' => true, 'versions' => $encounter->current_version, 'broken_at' => null];
    }

    public function isAuthor(User $user, Encounter $encounter): bool
    {
        return $this->doctorFor($user)?->id === $encounter->doctor_id;
    }

    private function create(User $actor, Doctor $doctor, Patient $patient, string $branchId, ?Appointment $appointment): Encounter
    {
        if ($patient->isAnonymized() || $patient->status !== 'active') {
            throw new BusinessRuleViolation('Paciente inativo ou anonimizado.', 'invalid_patient');
        }

        $encounter = new Encounter([
            'branch_id' => $branchId, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id,
            'appointment_id' => $appointment?->id, 'specialty_id' => $doctor->specialties()->value('specialties.id'),
            'started_at' => now(), 'created_by' => $actor->id,
        ]);

        // Pré-preenche sinais vitais e queixa a partir da triagem do dia.
        $triage = $this->triages->latestToday($patient->id);
        $encounter->draft_data = array_filter([
            'chief_complaint' => $triage?->chief_complaint,
            'vital_signs' => $triage?->summary(),
        ]);
        $encounter->save();

        $this->audit->record('medical_record.started', $encounter, metadata: ['patient_id' => $patient->id, 'appointment_id' => $appointment?->id]);

        return $encounter;
    }

    private function appendVersion(User $actor, Encounter $encounter, string $kind, array $content, ?string $reason): EncounterVersion
    {
        $diagnoses = $content['diagnoses'] ?? [];
        unset($content['diagnoses']);

        $last = EncounterVersion::query()->where('encounter_id', $encounter->id)->orderByDesc('version')->first();
        $number = ($last?->version ?? 0) + 1;
        $prev = $last?->hash ?? self::GENESIS;
        $doctor = $this->doctorFor($actor);

        $attributes = [
            'encounter_id' => $encounter->id, 'version' => $number, 'kind' => $kind,
            'data' => $content, 'diagnoses' => $diagnoses, 'reason' => $reason,
            'author_id' => $actor->id, 'author_doctor_id' => $doctor->id,
            'created_at' => now()->format('Y-m-d H:i:s'), 'prev_hash' => $prev,
        ];
        $attributes['hash'] = $this->hash($attributes, $prev);

        $version = EncounterVersion::create($attributes);

        foreach ($diagnoses as $d) {
            EncounterDiagnosis::create([
                'encounter_id' => $encounter->id, 'version' => $number, 'cid_code_id' => $d['cid_code_id'] ?? null,
                'code' => $d['code'], 'description' => $d['description'], 'cid_version' => $d['cid_version'] ?? 'CID-10',
                'is_primary' => (bool) ($d['is_primary'] ?? false), 'notes' => $d['notes'] ?? null,
            ]);
        }

        return $version;
    }

    /** Mantém apenas campos conhecidos; diagnósticos validados contra a base CID (cópia do texto). */
    private function sanitize(array $data): array
    {
        $clean = [];

        foreach (array_keys(Encounter::SECTIONS) as $section) {
            if (isset($data[$section]) && is_string($data[$section]) && trim($data[$section]) !== '') {
                $clean[$section] = mb_substr(str_replace("\r\n", "\n", $data[$section]), 0, self::MAX_TEXT);
            }
        }

        if (isset($data['return_in_days']) && is_numeric($data['return_in_days']) && (int) $data['return_in_days'] > 0) {
            $clean['return_in_days'] = min(365, (int) $data['return_in_days']);
        }

        $diagnoses = [];
        $ids = collect($data['diagnoses'] ?? [])->pluck('cid_code_id')->filter()->unique()->values();
        $cids = CidCode::query()->whereIn('id', $ids)->get()->keyBy('id');

        foreach (array_slice((array) ($data['diagnoses'] ?? []), 0, 10) as $d) {
            if (! $cid = $cids[$d['cid_code_id'] ?? ''] ?? null) {
                continue;
            }

            $diagnoses[] = [
                'cid_code_id' => $cid->id, 'code' => $cid->code, 'description' => $cid->description, 'cid_version' => $cid->version,
                'is_primary' => filter_var($d['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'notes' => isset($d['notes']) ? mb_substr((string) $d['notes'], 0, 255) : null,
            ];
        }

        // Exatamente um diagnóstico principal: o marcado, ou o primeiro da lista.
        $primary = collect($diagnoses)->search(fn ($d) => $d['is_primary']);
        foreach ($diagnoses as $i => $d) {
            $diagnoses[$i]['is_primary'] = $i === ($primary === false ? 0 : $primary);
        }

        if ($diagnoses !== []) {
            $clean['diagnoses'] = $diagnoses;
        }

        return $clean;
    }

    private function hash(array $v, string $prev): string
    {
        $canonical = function ($value) use (&$canonical) {
            if (is_string($value) && ($decoded = json_decode($value, true)) !== null && is_array($decoded)) {
                $value = $decoded;
            }
            if (is_array($value)) {
                if (! array_is_list($value)) {
                    ksort($value);
                }

                return array_map($canonical, $value);
            }

            return $value;
        };

        $payload = [
            'encounter_id' => $v['encounter_id'], 'version' => (int) $v['version'], 'kind' => $v['kind'],
            'data' => $canonical($v['data']), 'diagnoses' => $canonical($v['diagnoses']), 'reason' => $v['reason'],
            'author_id' => $v['author_id'], 'author_doctor_id' => $v['author_doctor_id'],
            'created_at' => substr((string) $v['created_at'], 0, 19),
        ];

        return hash_hmac('sha256', $prev.'|'.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'clinical|'.config('app.key'));
    }

    private function requireDoctor(User $actor): Doctor
    {
        return $this->doctorFor($actor)
            ?? throw new BusinessRuleViolation('Seu usuário não está vinculado a um cadastro de médico ativo.', 'not_a_doctor', 403);
    }

    private function ensureAuthor(User $actor, Encounter $encounter): void
    {
        if (! $this->isAuthor($actor, $encounter)) {
            $this->audit->record('access.denied', $encounter, result: 'denied', metadata: ['reason' => 'not_encounter_author']);

            throw new BusinessRuleViolation('Somente o médico responsável pode alterar este atendimento.', 'not_author', 403);
        }
    }
}
