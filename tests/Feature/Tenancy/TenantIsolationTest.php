<?php

namespace Tests\Feature\Tenancy;

use App\Core\Tenancy\Exceptions\CrossTenantViolation;
use App\Core\Tenancy\Exceptions\TenantContextMissing;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Organization\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Nenhum usuário de uma empresa pode visualizar ou manipular dados de outra.
 */
class TenantIsolationTest extends TestCase
{
    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->createClinic('Clínica A');
        $this->b = $this->createClinic('Clínica B');
    }

    public function test_listings_only_return_own_company_data(): void
    {
        $this->createBranch($this->b['company'], 'Filial secreta de B');

        $this->api($this->a['admin'])->getJson('/api/v1/branches')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonMissing(['name' => 'Filial secreta de B']);

        $emails = collect($this->api($this->a['admin'])->getJson('/api/v1/users')->json('data'))->pluck('email');
        $this->assertContains($this->a['admin']->email, $emails);
        $this->assertNotContains($this->b['admin']->email, $emails);
    }

    public function test_cannot_read_or_modify_another_company_records_by_id(): void
    {
        $client = $this->api($this->a['admin']);
        $bBranch = $this->b['branch'];
        $bAdmin = $this->b['admin'];
        $bRole = $this->roleId($this->b['company'], 'recepcao');

        $client->getJson("/api/v1/branches/{$bBranch->id}")->assertNotFound();
        $client->patchJson("/api/v1/branches/{$bBranch->id}", ['name' => 'invadida'])->assertNotFound();
        $client->getJson("/api/v1/users/{$bAdmin->id}")->assertNotFound();
        $client->patchJson("/api/v1/users/{$bAdmin->id}", ['name' => 'invadido'])->assertNotFound();
        $client->postJson("/api/v1/users/{$bAdmin->id}/block")->assertNotFound();
        $client->getJson("/api/v1/roles/{$bRole}")->assertNotFound();
        $client->patchJson("/api/v1/roles/{$bRole}", ['name' => 'x'])->assertNotFound();

        $this->assertSame($this->b['branch']->name, DB::table('branches')->where('id', $bBranch->id)->value('name'));
        $this->assertSame('active', DB::table('users')->where('id', $bAdmin->id)->value('status'));
    }

    public function test_company_id_sent_by_the_client_is_ignored(): void
    {
        $this->api($this->a['admin'])->postJson('/api/v1/branches', [
            'name' => 'Nova', 'code' => 'NOVA', 'company_id' => $this->b['company']->id,
        ])->assertCreated();

        $this->assertSame($this->a['company']->id, DB::table('branches')->where('code', 'NOVA')->value('company_id'));
    }

    public function test_cannot_assign_roles_or_branches_from_another_company(): void
    {
        $client = $this->api($this->a['admin']);
        $user = $this->userWithRole($this->a['company'], 'recepcao', $this->a['branch']);

        $client->putJson("/api/v1/users/{$user->id}/roles", ['roles' => [
            ['role_id' => $this->roleId($this->b['company'], 'admin_empresa'), 'branch_id' => null],
        ]])->assertUnprocessable();

        $client->putJson("/api/v1/users/{$user->id}/roles", ['roles' => [
            ['role_id' => $this->roleId($this->a['company'], 'recepcao'), 'branch_id' => $this->b['branch']->id],
        ]])->assertUnprocessable();
    }

    public function test_branch_header_from_another_company_is_rejected(): void
    {
        $this->api($this->a['admin'])->withHeader('X-Branch-Id', $this->b['branch']->id)
            ->getJson('/api/v1/branches')->assertForbidden();

        $this->assertDatabaseHas('audit_logs', ['action' => 'access.branch_denied', 'company_id' => $this->a['company']->id]);
    }

    public function test_audit_trail_is_isolated(): void
    {
        $ids = collect($this->api($this->a['admin'])->getJson('/api/v1/audit-logs?per_page=200')->json('data'))->pluck('id');
        $bLogIds = DB::table('audit_logs')->where('company_id', $this->b['company']->id)->pluck('id');

        $this->assertNotEmpty($bLogIds);
        $this->assertEmpty($ids->intersect($bLogIds));
    }

    public function test_querying_tenant_models_without_context_fails_closed(): void
    {
        $this->expectException(TenantContextMissing::class);
        Branch::query()->count();
    }

    public function test_writing_into_another_company_is_blocked_at_model_level(): void
    {
        $this->expectException(CrossTenantViolation::class);

        $this->context()->runFor($this->a['company']->id, function () {
            $branch = new Branch(['name' => 'X', 'code' => 'X']);
            $branch->company_id = $this->b['company']->id;
            $branch->save();
        });
    }

    public function test_company_id_is_immutable(): void
    {
        $this->expectException(CrossTenantViolation::class);

        $this->context()->runFor($this->a['company']->id, function () {
            $branch = Branch::query()->first();
            $branch->company_id = $this->b['company']->id;
            $branch->save();
        });
    }

    public function test_database_rejects_cross_company_links_even_bypassing_the_application(): void
    {
        $this->expectException(QueryException::class);

        // FK composta (company_id, role_id) → roles(company_id, id)
        DB::table('user_role_assignments')->insert([
            'id' => (string) str()->ulid(),
            'company_id' => $this->a['company']->id,
            'user_id' => $this->a['admin']->id,
            'role_id' => $this->roleId($this->b['company'], 'recepcao'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_role_assignments_are_scoped_by_context(): void
    {
        $countA = $this->context()->runFor($this->a['company']->id, fn () => RoleAssignment::query()->count());
        $total = DB::table('user_role_assignments')->count();

        $this->assertSame(1, $countA);
        $this->assertSame(2, $total);
    }
}
