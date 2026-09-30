<?php

namespace Tests;

use App\Core\Access\PermissionRegistry;
use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\TenantContext;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Branch;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Services\CompanyProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    public const PASSWORD = 'Senha@Forte123';

    protected function setUp(): void
    {
        parent::setUp();
        AuditLogger::$baseTransactionLevel = DB::transactionLevel();
        app(PermissionRegistry::class)->sync();
    }

    protected function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    /**
     * Provisiona uma clínica completa (empresa + matriz + perfis + admin).
     *
     * @return array{company: Company, branch: Branch, admin: User}
     */
    protected function createClinic(string $name = 'Clínica Alfa', array $companyOverrides = []): array
    {
        static $seq = 0;
        $seq++;

        return $this->context()->runAsSystem(fn () => app(CompanyProvisioningService::class)->provision(
            company: array_merge([
                'legal_name' => "{$name} Ltda",
                'trade_name' => $name,
                'document' => $this->fakeCnpj(),
                'status' => Company::STATUS_ACTIVE,
            ], $companyOverrides),
            headquarters: ['name' => "{$name} Matriz"],
            admin: ['name' => "Admin {$name}", 'email' => 'admin'.$seq.'.'.Str::lower(Str::random(6)).'@example.test', 'password' => self::PASSWORD],
        ));
    }

    /** Cria um usuário na empresa com um perfil (branch null = empresa toda). */
    protected function userWithRole(Company $company, string $roleKey, ?Branch $branch = null, array $attrs = []): User
    {
        return $this->context()->runFor($company->id, function () use ($roleKey, $branch, $attrs) {
            $user = User::create(array_merge([
                'name' => 'Usuário '.$roleKey,
                'email' => $roleKey.'.'.Str::lower(Str::random(8)).'@example.test',
                'password' => self::PASSWORD,
            ], $attrs));

            RoleAssignment::create([
                'user_id' => $user->id,
                'role_id' => Role::query()->where('key', $roleKey)->value('id'),
                'branch_id' => $branch?->id,
            ]);

            return $user;
        });
    }

    protected function createBranch(Company $company, string $name = 'Filial', array $attrs = []): Branch
    {
        return $this->context()->runFor($company->id, fn () => Branch::create(array_merge([
            'name' => $name,
            'code' => Str::upper(Str::random(6)),
        ], $attrs)));
    }

    protected function roleId(Company $company, string $key): string
    {
        return $this->context()->runFor($company->id, fn () => Role::query()->where('key', $key)->value('id'));
    }

    /** Autentica via token Sanctum real (fluxo completo da API). */
    protected function apiToken(User $user, string $password = self::PASSWORD): string
    {
        $response = $this->postJson('/api/v1/auth/token', [
            'email' => $user->email,
            'password' => $password,
            'device_name' => 'phpunit',
        ])->assertCreated();

        return $response->json('access_token');
    }

    /**
     * Cliente de API autenticado como $user. Limpa headers e guards entre
     * trocas de usuário (em produção cada requisição é um processo novo).
     */
    protected function api(User $user): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $token = $this->apiToken($user);
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    protected function fakeCnpj(): string
    {
        $base = str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT).'0001';

        foreach ([12, 13] as $len) {
            $weights = $len === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            $sum = 0;
            for ($i = 0; $i < $len; $i++) {
                $sum += (int) $base[$i] * $weights[$i];
            }
            $base .= $sum % 11 < 2 ? 0 : 11 - $sum % 11;
        }

        return $base;
    }
}
