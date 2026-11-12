<?php

namespace Tests;

use App\Core\Tenancy\TenantContext;
use App\Models\Account;
use App\Models\Company;
use App\Models\Membership;
use App\Models\Plan;
use App\Models\User;
use App\Services\AccountService;
use App\Services\WhatsApp\WhatsAppClient;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new PlanSeeder)->run();
        config(['wsf.security.account_admin_2fa' => false, 'wsf.security.platform_admin_2fa' => false]);
        WhatsAppClient::$sent = [];
    }

    /** @return array{0: Account, 1: User, 2: list<Company>} */
    protected function makeClient(string $plan = 'empresarial', array $companies = ['Empresa A'], ?string $email = null): array
    {
        $email ??= 'admin'.uniqid().'@teste.local';
        [$account, $user, $first] = app(AccountService::class)->register([
            'name' => 'Cliente '.uniqid(), 'person_type' => 'pj', 'email' => $email, 'company_name' => $companies[0],
            'admin_name' => 'Admin', 'admin_email' => $email, 'password' => 'Senha@Forte123',
        ], Plan::query()->where('code', $plan)->firstOrFail());
        $list = [$first];
        $this->ctx()->runFor($account->id, function () use ($companies, &$list, $first) {
            $first->update(['alias' => 'A']);
            foreach (array_slice($companies, 1) as $i => $name) {
                $list[] = app(AccountService::class)->createCompany(['name' => $name, 'alias' => chr(66 + $i), 'person_type' => 'pj'], false);
            }
        });

        return [$account->fresh('subscription.plan'), $user, $list];
    }

    protected function addMember(Account $account, string $role, array $companyIds = [], bool $all = false): User
    {
        $u = User::query()->create(['name' => ucfirst($role), 'email' => $role.uniqid().'@teste.local', 'password' => 'Senha@Forte123']);
        $this->ctx()->runFor($account->id, function () use ($account, $u, $role, $companyIds, $all) {
            $m = Membership::query()->create(['account_id' => $account->id, 'user_id' => $u->id, 'role' => $role, 'all_companies' => $all, 'status' => 'active']);
            $m->companies()->sync(array_fill_keys($companyIds, ['account_id' => $account->id]));
        });

        return $u;
    }

    protected function ctx(): TenantContext
    {
        return app(TenantContext::class);
    }

    protected function inAccount(Account $account, \Closure $fn): mixed
    {
        return $this->ctx()->runFor($account->id, $fn);
    }
}
