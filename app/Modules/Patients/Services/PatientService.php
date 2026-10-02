<?php

namespace App\Modules\Patients\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Core\Support\Format;
use App\Core\Support\SequenceGenerator;
use App\Core\Tenancy\TenantContext;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AccessGuard;
use App\Modules\Insurance\Models\InsurancePlan;
use App\Modules\Insurance\Models\Insurer;
use App\Modules\Organization\Models\Branch;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Models\PatientConsent;
use App\Modules\Portal\Models\PatientAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PatientService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SequenceGenerator $sequences,
        private readonly AccessGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validado (PatientRequest)
     */
    public function create(User $actor, array $data, bool $confirmDuplicate = false): Patient
    {
        $data = $this->normalize($data);
        $this->ensureGuardianForMinor($data);

        if (! $confirmDuplicate) {
            $candidates = $this->possibleDuplicates($data);

            if ($candidates->isNotEmpty()) {
                throw new DuplicatePatientCandidates($candidates);
            }
        }

        $data['home_branch_id'] ??= $this->context->branchId();
        $this->ensureBranchAllowed($data['home_branch_id']);

        return DB::transaction(function () use ($actor, $data) {
            $patient = new Patient($this->attributes($data));
            $patient->record_number = $this->sequences->next($this->context->companyId(), 'patient_record');
            $patient->created_by = $actor->id;
            $patient->save();

            $this->syncContacts($patient, $data['contacts'] ?? [], isNew: true);
            $this->syncInsurances($patient, $data['insurances'] ?? [], isNew: true);

            return $patient;
        });
    }

    public function update(User $actor, Patient $patient, array $data): Patient
    {
        $this->ensureNotAnonymized($patient);
        $data = $this->normalize($data);
        $this->ensureGuardianForMinor($data + ['birth_date' => $patient->birth_date?->format('Y-m-d')], $patient);

        if (array_key_exists('home_branch_id', $data)) {
            $this->ensureBranchAllowed($data['home_branch_id']);
        }

        return DB::transaction(function () use ($patient, $data) {
            $patient->update($this->attributes($data));

            if (array_key_exists('contacts', $data)) {
                $this->syncContacts($patient, $data['contacts'], isNew: false);
            }

            if (array_key_exists('insurances', $data)) {
                $this->syncInsurances($patient, $data['insurances'], isNew: false);
            }

            return $patient;
        });
    }

    public function setStatus(Patient $patient, string $status): Patient
    {
        $this->ensureNotAnonymized($patient);
        $patient->update(['status' => $status]);

        return $patient;
    }

    public function recordConsent(User $actor, Patient $patient, string $purpose, bool $granted, string $channel, ?string $notes = null): PatientConsent
    {
        $this->ensureNotAnonymized($patient);

        $consent = PatientConsent::create([
            'patient_id' => $patient->id,
            'purpose' => $purpose,
            'term_version' => config("consents.purposes.{$purpose}.version"),
            'granted' => $granted,
            'channel' => $channel,
            'notes' => $notes,
            'recorded_by' => $actor->id,
            'ip_address' => request()->ip(),
        ]);

        $this->audit->record($granted ? 'patient.consent_granted' : 'patient.consent_revoked', $patient,
            new: ['purpose' => $purpose, 'term_version' => $consent->term_version, 'channel' => $channel]);

        return $consent;
    }

    /** Registro de acesso ao cadastro (LGPD — rastreabilidade de acesso a dados sensíveis). */
    public function recordView(Patient $patient, string $channel): void
    {
        $this->audit->record('patient.viewed', $patient, metadata: ['channel' => $channel]);
    }

    /**
     * Exportação dos dados do titular (LGPD art. 18, II e V).
     *
     * @return array<string, mixed>
     */
    public function export(User $actor, Patient $patient): array
    {
        $patient->loadMissing(['contacts', 'insurances', 'consents', 'homeBranch']);

        $this->audit->record('patient.exported', $patient);

        return [
            'exported_at' => now()->toIso8601String(),
            'controller' => $patient->company?->only(['legal_name', 'trade_name', 'document']),
            'patient' => collect($patient->only([
                'record_number', 'name', 'social_name', 'cpf', 'rg', 'rg_issuer', 'cns', 'sex', 'gender_identity',
                'mother_name', 'phone', 'whatsapp', 'email', 'zip_code', 'street', 'number', 'complement',
                'district', 'city', 'state', 'preferred_contact', 'status',
            ]))->put('birth_date', $patient->birth_date?->format('Y-m-d'))->put('created_at', $patient->created_at?->toIso8601String())->all(),
            'contacts' => $patient->contacts->map->only(['type', 'name', 'relationship', 'cpf', 'phone', 'email'])->values(),
            'insurances' => $patient->insurances->map(fn ($i) => $i->only(['insurer_name', 'plan_name', 'card_number']) + ['valid_until' => $i->valid_until?->format('Y-m-d')])->values(),
            'consents' => $patient->consents->map(fn ($c) => $c->only(['purpose', 'term_version', 'granted', 'channel']) + ['recorded_at' => $c->created_at?->toIso8601String()])->values(),
            'notice' => 'Dados clínicos (prontuário) são disponibilizados mediante solicitação formal à clínica, conforme CFM e LGPD.',
        ];
    }

    /**
     * Anonimização (LGPD art. 18, IV): remove identificadores diretos e mantém o
     * registro (nº de prontuário, ano de nascimento, sexo, cidade/UF) para retenção
     * legal e estatísticas. Irreversível. Não copia dados pessoais para a auditoria.
     */
    public function anonymize(User $actor, Patient $patient, string $reason): Patient
    {
        if (! $this->guard->hasCompanyWide($actor, 'paciente.anonimizar')) {
            $this->guard->deny('company_wide_required', ['permission' => 'paciente.anonimizar']);
        }

        $this->ensureNotAnonymized($patient);

        DB::transaction(function () use ($patient) {
            Patient::withoutAuditing(function () use ($patient) {
                $patient->forceFill([
                    'name' => 'Paciente anonimizado #'.$patient->record_number,
                    'social_name' => null, 'cpf' => null, 'rg' => null, 'rg_issuer' => null, 'cns' => null,
                    'birth_date' => $patient->birth_date?->copy()->startOfYear(),
                    'gender_identity' => null, 'mother_name' => null, 'phone' => null, 'whatsapp' => null, 'email' => null,
                    'zip_code' => null, 'street' => null, 'number' => null, 'complement' => null, 'district' => null,
                    'preferred_contact' => null, 'notes' => null, 'status' => 'inactive', 'anonymized_at' => now(),
                ])->save();
            });

            $patient->contacts()->delete();
            // Portal: acesso bloqueado e e-mail de login removido.
            PatientAccount::query()->where('patient_id', $patient->id)->update(['status' => 'blocked', 'email' => null, 'updated_at' => now()]);
            // Carteirinhas usadas em guias ficam (faturamento ao convênio é obrigação legal — LGPD art. 16, I),
            // mas desativadas e com o número mascarado no cadastro; as demais são apagadas.
            foreach ($patient->allInsurances()->get() as $insurance) {
                $insurance->isReferenced()
                    ? $insurance->update(['is_active' => false, 'is_primary' => false, 'card_number' => '***'.substr($insurance->card_number, -4), 'valid_until' => null])
                    : $insurance->delete();
            }
        });

        $this->audit->record('patient.anonymized', $patient, metadata: ['reason' => $reason]);

        return $patient;
    }

    /** Histórico de alterações do cadastro (a partir da trilha de auditoria). */
    public function history(Patient $patient, int $limit = 50): Collection
    {
        return AuditLog::query()->with('user:id,name')
            ->where('auditable_type', 'patient')->where('auditable_id', $patient->id)
            ->where('action', '!=', 'patient.viewed')
            ->orderByDesc('id')->limit($limit)->get();
    }

    /** Mesmo nome + nascimento, ou mesmo celular/WhatsApp. */
    public function possibleDuplicates(array $data, ?string $ignoreId = null): Collection
    {
        $name = Format::searchable($data['name'] ?? '');
        $phones = array_filter([$data['phone'] ?? null, $data['whatsapp'] ?? null]);

        if ($name === '' && $phones === []) {
            return collect();
        }

        return Patient::query()
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->whereNull('anonymized_at')
            ->where(function ($q) use ($name, $data, $phones) {
                if (! empty($data['birth_date']) && $name !== '') {
                    $q->orWhere(fn ($w) => $w->where('search_name', 'like', $name.'%')->whereDate('birth_date', $data['birth_date']));
                }
                foreach ($phones as $phone) {
                    $q->orWhere('phone', $phone)->orWhere('whatsapp', $phone);
                }
            })
            ->limit(5)->get();
    }

    private function normalize(array $data): array
    {
        foreach (['cpf', 'cns', 'phone', 'whatsapp', 'zip_code'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = Format::digits($data[$key]);
            }
        }

        foreach (['name', 'social_name', 'mother_name'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = Format::personName($data[$key]);
            }
        }

        if (! empty($data['state'])) {
            $data['state'] = strtoupper($data['state']);
        }

        foreach ($data['contacts'] ?? [] as $i => $contact) {
            $data['contacts'][$i]['cpf'] = Format::digits($contact['cpf'] ?? null);
            $data['contacts'][$i]['phone'] = Format::digits($contact['phone'] ?? null);
        }

        return $data;
    }

    private function attributes(array $data): array
    {
        return array_intersect_key($data, array_flip((new Patient)->getFillable()));
    }

    /** Menor de idade precisa de responsável legal cadastrado. */
    private function ensureGuardianForMinor(array $data, ?Patient $patient = null): void
    {
        if (empty($data['birth_date']) || now()->diffInYears($data['birth_date'], true) >= 18) {
            return;
        }

        $contacts = array_key_exists('contacts', $data) ? collect($data['contacts']) : ($patient?->contacts ?? collect());
        $hasGuardian = $contacts->contains(fn ($c) => data_get($c, 'type') === 'guardian');

        if (! $hasGuardian) {
            throw new BusinessRuleViolation('Paciente menor de idade: cadastre o responsável legal.', 'guardian_required');
        }
    }

    private function ensureBranchAllowed(?string $branchId): void
    {
        if ($branchId === null) {
            return;
        }

        if (! Branch::query()->accessible($this->context->allowedBranchIds())->whereKey($branchId)->exists()) {
            throw new BusinessRuleViolation('Filial inválida ou fora do seu escopo.');
        }
    }

    private function ensureNotAnonymized(Patient $patient): void
    {
        if ($patient->isAnonymized()) {
            throw new BusinessRuleViolation('Paciente anonimizado: o cadastro não pode mais ser alterado.', 'patient_anonymized');
        }
    }

    private function syncContacts(Patient $patient, array $contacts, bool $isNew): void
    {
        $old = $isNew ? [] : $patient->contacts()->get(['type', 'name', 'relationship'])->toArray();
        $patient->contacts()->delete();

        foreach ($contacts as $contact) {
            $patient->contacts()->create(array_intersect_key($contact, array_flip(['type', 'name', 'relationship', 'cpf', 'phone', 'email'])));
        }

        $new = collect($contacts)->map(fn ($c) => array_intersect_key($c, array_flip(['type', 'name', 'relationship'])))->all();

        if (! $isNew && $old != $new) {
            $this->audit->record('patient.contacts_changed', $patient, old: ['contacts' => $old], new: ['contacts' => $new]);
        }
    }

    /**
     * Atualiza as carteirinhas pelo id (mantém o vínculo com agendamentos/guias). Carteirinha
     * removida do formulário é apagada, ou só desativada se já foi usada em guia/autorização/agenda.
     */
    private function syncInsurances(Patient $patient, array $insurances, bool $isNew): void
    {
        $current = $isNew ? collect() : $patient->insurances()->get()->keyBy('id');
        $old = $current->map(fn ($i) => ['insurer_name' => $i->insurer_name, 'card_number' => $i->card_number])->values()->all();
        $hasPrimary = false;
        $kept = [];

        foreach ($insurances as $insurance) {
            $primary = ! $hasPrimary && ! empty($insurance['is_primary']);
            $hasPrimary = $hasPrimary || $primary;
            $values = [
                ...array_intersect_key($insurance, array_flip(['insurer_id', 'plan_id', 'insurer_name', 'plan_name', 'card_number', 'valid_until'])),
                'is_primary' => $primary,
            ];
            $values['insurer_id'] = ($values['insurer_id'] ?? null) ?: null;
            $values['plan_id'] = ($values['plan_id'] ?? null) ?: null;
            $values['valid_until'] = ($values['valid_until'] ?? null) ?: null;
            // Convênio cadastrado: o nome exibido vem do cadastro (não do formulário).
            if ($values['insurer_id']) {
                $values['insurer_name'] = Insurer::query()->whereKey($values['insurer_id'])->value('name');
                $values['plan_name'] = $values['plan_id'] ? InsurancePlan::query()->whereKey($values['plan_id'])->value('name') : ($values['plan_name'] ?? null);
            }

            if (! empty($insurance['id']) && $current->has($insurance['id'])) {
                $current[$insurance['id']]->update($values);
                $kept[] = $insurance['id'];
            } else {
                $kept[] = $patient->insurances()->create($values)->id;
            }
        }

        foreach ($current->except($kept) as $removed) {
            $removed->isReferenced() ? $removed->update(['is_active' => false, 'is_primary' => false]) : $removed->delete();
        }

        $new = collect($insurances)->map(fn ($i) => ['insurer_name' => $i['insurer_name'], 'card_number' => $i['card_number']])->all();

        if (! $isNew && $old != $new) {
            $this->audit->record('patient.insurances_changed', $patient, old: ['insurances' => $old], new: ['insurances' => $new]);
        }
    }
}
