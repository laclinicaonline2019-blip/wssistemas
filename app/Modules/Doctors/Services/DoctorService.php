<?php

namespace App\Modules\Doctors\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Core\Tenancy\TenantContext;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AccessGuard;
use App\Modules\Organization\Models\Branch;
use App\Modules\Platform\Services\PlanLimitService;
use Illuminate\Support\Facades\DB;

class DoctorService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PlanLimitService $limits,
        private readonly AccessGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{specialties?: list<array{id: string, rqe?: string|null}>, branches?: list<string>}  $data
     */
    public function create(User $actor, array $data): Doctor
    {
        return DB::transaction(function () use ($actor, $data) {
            $this->limits->ensureCanAdd($this->context->companyId(), 'max_doctors', fn () => Doctor::query()->count());
            $this->validateUser($data['user_id'] ?? null);

            $doctor = Doctor::create($this->attributes($data));
            $this->syncRelations($actor, $doctor, $data, isNew: true);

            return $doctor->load(['specialties', 'branches', 'user']);
        });
    }

    public function update(User $actor, Doctor $doctor, array $data): Doctor
    {
        $this->ensureCanManage($actor, $doctor);

        return DB::transaction(function () use ($actor, $doctor, $data) {
            if (array_key_exists('user_id', $data)) {
                $this->validateUser($data['user_id'], $doctor);
            }

            $doctor->update($this->attributes($data));
            $this->syncRelations($actor, $doctor, $data, isNew: false);

            return $doctor->load(['specialties', 'branches', 'user']);
        });
    }

    public function setStatus(User $actor, Doctor $doctor, string $status): Doctor
    {
        $this->ensureCanManage($actor, $doctor);
        $doctor->update(['status' => $status]);

        return $doctor;
    }

    /** Gestor restrito a filiais só gerencia médicos que atendem exclusivamente nelas. */
    public function canManage(User $actor, Doctor $doctor): bool
    {
        $allowed = $actor->allowedBranchIds();

        if ($allowed === null) {
            return true;
        }

        $branches = $doctor->branches()->pluck('branches.id')->all();

        return $branches !== [] && array_diff($branches, $allowed) === [];
    }

    private function ensureCanManage(User $actor, Doctor $doctor): void
    {
        if (! $this->canManage($actor, $doctor)) {
            $this->guard->deny('doctor_outside_scope', ['doctor_id' => $doctor->id]);
        }
    }

    private function attributes(array $data): array
    {
        return array_intersect_key($data, array_flip(['user_id', 'name', 'social_name', 'crm', 'crm_state', 'cpf', 'email', 'phone', 'bio']));
    }

    private function validateUser(?string $userId, ?Doctor $current = null): void
    {
        if ($userId === null) {
            return;
        }

        if (! User::query()->whereKey($userId)->exists()) {
            throw new BusinessRuleViolation('Usuário vinculado inválido.');
        }

        $taken = Doctor::query()->where('user_id', $userId)->when($current, fn ($q) => $q->whereKeyNot($current->id))->exists();

        if ($taken) {
            throw new BusinessRuleViolation('Este usuário já está vinculado a outro médico.');
        }
    }

    private function syncRelations(User $actor, Doctor $doctor, array $data, bool $isNew): void
    {
        $changes = [];

        if (array_key_exists('specialties', $data)) {
            $ids = collect($data['specialties'])->pluck('id')->unique();
            $valid = Specialty::query()->whereIn('id', $ids)->pluck('id');

            if ($valid->count() !== $ids->count()) {
                throw new BusinessRuleViolation('Especialidade inválida.');
            }

            $sync = collect($data['specialties'])->mapWithKeys(fn ($s) => [$s['id'] => ['rqe' => $s['rqe'] ?? null, 'company_id' => $doctor->company_id]])->all();
            $old = $isNew ? [] : $doctor->specialties()->pluck('specialties.id')->all();
            $doctor->specialties()->sync($sync);
            $changes['specialties'] = ['old' => $old, 'new' => array_keys($sync)];
        }

        if (array_key_exists('branches', $data) || $isNew) {
            $ids = array_values(array_unique($data['branches'] ?? []));
            $allowed = $actor->allowedBranchIds();

            if ($ids === [] && $allowed !== null) {
                throw new BusinessRuleViolation('Informe ao menos uma filial sob sua gestão.');
            }

            $valid = Branch::query()->whereIn('id', $ids)->accessible($allowed)->pluck('id');

            if ($valid->count() !== count($ids)) {
                throw new BusinessRuleViolation('Filial inválida ou fora do seu escopo.');
            }

            $old = $isNew ? [] : $doctor->branches()->pluck('branches.id')->all();
            $doctor->branches()->sync(collect($ids)->mapWithKeys(fn ($id) => [$id => ['company_id' => $doctor->company_id]])->all());
            $changes['branches'] = ['old' => $old, 'new' => $ids];
        }

        foreach ($changes as $relation => $diff) {
            sort($diff['old']);
            sort($diff['new']);

            if ($diff['old'] !== $diff['new']) {
                $this->audit->record("doctor.{$relation}_changed", $doctor, old: [$relation => $diff['old']], new: [$relation => $diff['new']]);
            }
        }
    }
}
