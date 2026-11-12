<?php

namespace App\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRule;
use App\Core\Tenancy\TenantContext;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Membership;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\BillingService;
use Illuminate\Support\Facades\DB;

/** Cadastro de clientes (autoatendimento ou pelo proprietário), empresas e usuários. */
class AccountService
{
    public function __construct(private readonly TenantContext $ctx, private readonly BillingService $billing, private readonly AuditLogger $audit) {}

    /**
     * @param  array{name:string, person_type:string, document?:?string, email:string, phone?:?string, company_name:string, admin_name:string, admin_email:string, password:string}  $d
     */
    public function register(array $d, Plan $plan, string $cycle = 'monthly', ?int $trialDays = null): array
    {
        if (User::query()->where('email', mb_strtolower($d['admin_email']))->exists()) {
            throw new BusinessRule('Já existe um usuário com este e-mail. Entre com ele ou recupere a senha.', 'email');
        }

        return DB::transaction(function () use ($d, $plan, $cycle, $trialDays) {
            $account = Account::query()->create(['name' => $d['name'], 'person_type' => $d['person_type'], 'document' => $d['document'] ?? null,
                'email' => mb_strtolower($d['email']), 'phone' => $d['phone'] ?? null, 'status' => 'active']);
            $user = User::query()->create(['name' => $d['admin_name'], 'email' => mb_strtolower($d['admin_email']), 'password' => $d['password']]);

            [$company] = $this->ctx->runFor($account->id, function () use ($account, $user, $d) {
                Membership::query()->create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'admin', 'all_companies' => true, 'status' => 'active']);
                $company = $this->createCompany(['name' => $d['company_name'], 'person_type' => $d['person_type'], 'document' => $d['document'] ?? null], false);

                return [$company];
            });
            $this->billing->startTrial($account, $plan, $cycle, $trialDays);
            $this->audit->log('cliente.cadastrar', $account, ['plano' => $plan->code], accountId: $account->id, userId: $user->id);

            return [$account, $user, $company];
        });
    }

    public function createCompany(array $d, bool $checkLimit = true): Company
    {
        if ($checkLimit) {
            $this->billing->assertCanAdd(Account::query()->with('subscription.plan')->findOrFail($this->ctx->accountId()), 'company');
        }
        $company = Company::query()->create(['name' => trim($d['name']), 'alias' => isset($d['alias']) ? trim((string) $d['alias']) ?: null : null,
            'person_type' => in_array($d['person_type'] ?? 'pj', ['pf', 'pj'], true) ? $d['person_type'] : 'pj', 'document' => $d['document'] ?? null, 'active' => true]);
        CompanySetting::query()->create(['company_id' => $company->id, 'debt_budget_cents' => 0, 'debt_strategy' => 'avalanche']);
        $this->audit->log('empresa.criar', $company, ['nome' => $company->name]);

        return $company;
    }

    /** Convida/cria usuário no cliente atual. Se o e-mail já existe (ex.: contador de outro cliente), apenas vincula. */
    public function addUser(array $d, ?string $password): array
    {
        $account = Account::query()->with('subscription.plan')->findOrFail($this->ctx->accountId());
        $this->billing->assertCanAdd($account, 'user');
        $role = $d['role'];
        if (! array_key_exists($role, Membership::ROLES)) {
            throw new BusinessRule('Papel inválido.');
        }
        $plan = $account->plan();
        if (in_array($role, ['gestor', 'financeiro', 'consulta'], true) && ! $plan->has('advanced_permissions')) {
            throw new BusinessRule('Perfis gestor, financeiro e consulta estão disponíveis no plano Empresarial.', 'plano');
        }
        if ($role === 'contador' && ! $plan->has('accountant')) {
            throw new BusinessRule('O acesso do contador não está incluído no seu plano.', 'plano');
        }

        return DB::transaction(function () use ($d, $password, $role) {
            $email = mb_strtolower(trim($d['email']));
            $user = User::query()->where('email', $email)->first();
            $created = false;
            if (! $user) {
                $user = User::query()->create(['name' => $d['name'], 'email' => $email, 'password' => $password ?? bin2hex(random_bytes(24))]);
                $created = true;
            }
            if (Membership::query()->where('user_id', $user->id)->exists()) {
                throw new BusinessRule('Este usuário já faz parte da sua conta.');
            }
            $all = $role !== 'contador' && ! empty($d['all_companies']);
            $m = Membership::query()->create(['account_id' => $this->ctx->accountId(), 'user_id' => $user->id, 'role' => $role, 'all_companies' => $all, 'status' => 'active']);
            $this->syncCompanies($m, $all ? [] : ($d['companies'] ?? []));
            $this->audit->log('usuario.vincular', $m, ['email' => $email, 'papel' => $role, 'empresas' => $d['companies'] ?? 'todas']);

            return [$user, $m, $created];
        });
    }

    public function updateMembership(Membership $m, array $d): void
    {
        if ($m->user_id === $this->ctx->userId() && ($d['role'] !== 'admin' || ($d['status'] ?? 'active') !== 'active')) {
            throw new BusinessRule('Você não pode rebaixar ou desativar o seu próprio acesso.');
        }
        if ($m->role === 'admin' && ($d['role'] !== 'admin' || ($d['status'] ?? 'active') !== 'active')
            && Membership::query()->where('role', 'admin')->where('status', 'active')->count() <= 1) {
            throw new BusinessRule('O cliente precisa de pelo menos um administrador ativo.');
        }
        $all = $d['role'] !== 'contador' && ! empty($d['all_companies']);
        $m->update(['role' => $d['role'], 'all_companies' => $all, 'status' => $d['status'] ?? 'active']);
        $this->syncCompanies($m, $all ? [] : ($d['companies'] ?? []));
        $this->audit->log('usuario.permissoes', $m, ['papel' => $d['role'], 'empresas' => $all ? 'todas' : ($d['companies'] ?? []), 'status' => $m->status]);
    }

    /** Só empresas do próprio cliente (e às quais o administrador tem acesso) podem ser autorizadas. */
    private function syncCompanies(Membership $m, array $companyIds): void
    {
        $valid = Company::query()->whereIn('id', $companyIds)->pluck('id')->all();
        $m->companies()->sync(array_fill_keys($valid, ['account_id' => $m->account_id]));
    }
}
