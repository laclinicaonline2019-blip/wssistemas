<?php

namespace Tests\Feature\Access;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\SaasPlan;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    public function test_reception_cannot_manage_users_audit_or_company(): void
    {
        ['company' => $company, 'branch' => $branch] = $this->createClinic();
        $recepcao = $this->userWithRole($company, 'recepcao', $branch);
        $client = $this->api($recepcao);

        $client->getJson('/api/v1/users')->assertForbidden();
        $client->postJson('/api/v1/users', [])->assertForbidden();
        $client->getJson('/api/v1/audit-logs')->assertForbidden();
        $client->patchJson('/api/v1/company', ['trade_name' => 'x'])->assertForbidden();
        $client->postJson('/api/v1/roles', ['name' => 'x', 'permissions' => []])->assertForbidden();

        $this->assertDatabaseHas('audit_logs', ['action' => 'access.denied', 'user_id' => $recepcao->id, 'result' => 'denied']);
    }

    public function test_doctor_cannot_access_administration(): void
    {
        ['company' => $company] = $this->createClinic();
        $medico = $this->userWithRole($company, 'medico');

        $this->api($medico)->getJson('/api/v1/users')->assertForbidden();
        $this->api($medico)->getJson('/api/v1/branches')->assertForbidden();
    }

    public function test_branch_admin_is_limited_to_own_branch(): void
    {
        ['company' => $company, 'branch' => $hq, 'admin' => $admin] = $this->createClinic();
        $sul = $this->createBranch($company, 'Zona Sul');
        $gestor = $this->userWithRole($company, 'admin_filial', $sul);
        $recepSul = $this->userWithRole($company, 'recepcao', $sul);
        $recepHq = $this->userWithRole($company, 'recepcao', $hq);
        $client = $this->api($gestor);

        // Filiais: só enxerga/edita a própria; não cria nem desativa.
        $client->getJson('/api/v1/branches')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sul->id);
        $client->patchJson("/api/v1/branches/{$sul->id}", ['name' => 'Zona Sul II'])->assertOk();
        $client->patchJson("/api/v1/branches/{$hq->id}", ['name' => 'x'])->assertNotFound();
        $client->postJson('/api/v1/branches', ['name' => 'Nova', 'code' => 'NOVA'])->assertForbidden();

        // Usuários: só os vinculados exclusivamente à sua filial.
        $emails = collect($client->getJson('/api/v1/users')->assertOk()->json('data'))->pluck('email');
        $this->assertContains($recepSul->email, $emails);
        $this->assertNotContains($recepHq->email, $emails);
        $this->assertNotContains($admin->email, $emails);

        $client->patchJson("/api/v1/users/{$recepHq->id}", ['name' => 'x'])->assertForbidden();
        $client->postJson("/api/v1/users/{$admin->id}/block")->assertForbidden();
        $this->assertSame('active', $admin->fresh()->status);
    }

    public function test_privilege_escalation_is_blocked(): void
    {
        ['company' => $company, 'branch' => $hq, 'admin' => $admin] = $this->createClinic();
        $sul = $this->createBranch($company, 'Zona Sul');
        $gestor = $this->userWithRole($company, 'admin_filial', $sul);
        $recep = $this->userWithRole($company, 'recepcao', $sul);
        $client = $this->api($gestor);

        // Conceder perfil de administrador da empresa
        $client->putJson("/api/v1/users/{$recep->id}/roles", ['roles' => [
            ['role_id' => $this->roleId($company, 'admin_empresa'), 'branch_id' => $sul->id],
        ]])->assertForbidden();

        // Conceder acesso a toda a empresa
        $client->putJson("/api/v1/users/{$recep->id}/roles", ['roles' => [
            ['role_id' => $this->roleId($company, 'recepcao'), 'branch_id' => null],
        ]])->assertForbidden();

        // Conceder acesso a outra filial
        $client->putJson("/api/v1/users/{$recep->id}/roles", ['roles' => [
            ['role_id' => $this->roleId($company, 'recepcao'), 'branch_id' => $hq->id],
        ]])->assertForbidden();

        // Médico tem permissões clínicas que o gestor de filial não possui
        $client->putJson("/api/v1/users/{$recep->id}/roles", ['roles' => [
            ['role_id' => $this->roleId($company, 'medico'), 'branch_id' => $sul->id],
        ]])->assertForbidden();

        // Enfermagem dá acesso a prontuário, que o gestor de filial não possui (operação atômica: nada é aplicado).
        $client->putJson("/api/v1/users/{$recep->id}/roles", ['roles' => [
            ['role_id' => $this->roleId($company, 'recepcao'), 'branch_id' => $sul->id],
            ['role_id' => $this->roleId($company, 'enfermagem'), 'branch_id' => $sul->id],
        ]])->assertForbidden();
        $this->assertSame(1, $recep->roleAssignments()->count());

        // Financeiro inclui repasses/conciliação, que o gestor de filial não possui.
        $client->putJson("/api/v1/users/{$recep->id}/roles", ['roles' => [
            ['role_id' => $this->roleId($company, 'financeiro'), 'branch_id' => $sul->id],
        ]])->assertForbidden();

        // Permitido: perfil contido nas permissões do gestor, na filial dele.
        $auxiliar = $this->api($admin)->postJson('/api/v1/roles', ['name' => 'Auxiliar', 'permissions' => ['agenda.visualizar', 'fila.gerenciar']])
            ->assertCreated()->json('data.id');
        $this->api($gestor)->putJson("/api/v1/users/{$recep->id}/roles", ['roles' => [
            ['role_id' => $this->roleId($company, 'recepcao'), 'branch_id' => $sul->id],
            ['role_id' => $auxiliar, 'branch_id' => $sul->id],
        ]])->assertOk()->assertJsonCount(2, 'data.roles');

        $this->assertDatabaseHas('audit_logs', ['action' => 'access.denied', 'user_id' => $gestor->id]);
    }

    public function test_users_cannot_change_their_own_roles_or_block_themselves(): void
    {
        ['company' => $company, 'admin' => $admin] = $this->createClinic();
        $client = $this->api($admin);

        $client->putJson("/api/v1/users/{$admin->id}/roles", ['roles' => []])->assertForbidden();
        $client->postJson("/api/v1/users/{$admin->id}/block")->assertForbidden();
    }

    public function test_company_keeps_at_least_one_active_admin(): void
    {
        ['company' => $company, 'admin' => $admin] = $this->createClinic();
        $admin2 = $this->userWithRole($company, 'admin_empresa');

        // Com outro admin ativo, é possível rebaixar/bloquear um administrador.
        $this->api($admin2)->putJson("/api/v1/users/{$admin->id}/roles", ['roles' => []])->assertOk();

        // Gerente com permissões de usuários na empresa toda (sem ser admin).
        $roleId = $this->api($admin2)->postJson('/api/v1/roles', [
            'name' => 'Gerente de pessoas',
            'permissions' => ['usuario.visualizar', 'usuario.editar', 'usuario.bloquear'],
        ])->assertCreated()->json('data.id');
        $gerente = $this->userWithRole($company, 'recepcao');
        $this->api($admin2)->putJson("/api/v1/users/{$gerente->id}/roles", ['roles' => [['role_id' => $roleId, 'branch_id' => null]]])->assertOk();

        // admin2 é o último administrador ativo: não pode ser bloqueado.
        $this->api($gerente)->postJson("/api/v1/users/{$admin2->id}/block")
            ->assertUnprocessable()->assertJsonPath('code', 'last_admin');
        $this->assertSame('active', $admin2->fresh()->status);
    }

    public function test_custom_role_cannot_exceed_creator_permissions_and_locked_roles_are_immutable(): void
    {
        ['company' => $company, 'admin' => $admin] = $this->createClinic();
        $client = $this->api($admin);

        $roleId = $client->postJson('/api/v1/roles', ['name' => 'Faturista', 'permissions' => ['financeiro.visualizar', 'pagamento.visualizar']])
            ->assertCreated()->json('data.id');
        $client->patchJson("/api/v1/roles/{$roleId}", ['permissions' => ['financeiro.visualizar', 'platform.empresas']])->assertUnprocessable();

        $locked = $this->roleId($company, 'admin_empresa');
        $client->patchJson("/api/v1/roles/{$locked}", ['permissions' => []])->assertUnprocessable();
        $client->deleteJson('/api/v1/roles/'.$this->roleId($company, 'medico'))->assertUnprocessable();
        $client->deleteJson("/api/v1/roles/{$roleId}")->assertNoContent();
    }

    public function test_platform_and_tenant_areas_are_mutually_exclusive(): void
    {
        ['admin' => $admin] = $this->createClinic();
        $super = $this->context()->runAsSystem(function () {
            $u = new User(['name' => 'Root', 'email' => 'root@example.test', 'password' => self::PASSWORD]);
            $u->is_super_admin = true;
            $u->save();

            return $u;
        });

        $this->api($admin)->getJson('/api/v1/platform/companies')->assertForbidden();
        $this->api($super)->getJson('/api/v1/branches')->assertForbidden();
        $this->api($super)->getJson('/api/v1/platform/companies')->assertOk();
    }

    public function test_plan_limits_are_enforced(): void
    {
        $plan = SaasPlan::create(['code' => 'mini', 'name' => 'Mini', 'price_monthly_cents' => 100, 'price_yearly_cents' => 1000,
            'limits' => ['max_users' => 2, 'max_branches' => 1]]);
        ['admin' => $admin] = $this->createClinic('Pequena', ['saas_plan_id' => $plan->id]);
        $client = $this->api($admin);

        $payload = fn ($n) => ['name' => "U{$n}", 'email' => "u{$n}@example.test", 'password' => 'Senha@Forte123', 'password_confirmation' => 'Senha@Forte123'];
        $client->postJson('/api/v1/users', $payload(1))->assertCreated();
        $client->postJson('/api/v1/users', $payload(2))->assertUnprocessable()->assertJsonPath('code', 'plan_limit_reached');
        $client->postJson('/api/v1/branches', ['name' => 'F2', 'code' => 'F2'])->assertUnprocessable()->assertJsonPath('code', 'plan_limit_reached');
    }

    public function test_new_users_must_change_the_initial_password(): void
    {
        ['admin' => $admin, 'branch' => $branch, 'company' => $company] = $this->createClinic();

        $id = $this->api($admin)->postJson('/api/v1/users', [
            'name' => 'Nova Recepcionista', 'email' => 'nova@example.test',
            'password' => 'Senha@Forte123', 'password_confirmation' => 'Senha@Forte123',
            'roles' => [['role_id' => $this->roleId($company, 'recepcao'), 'branch_id' => $branch->id]],
        ])->assertCreated()->json('data.id');

        $user = User::find($id);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue($user->hasPermission('agenda.criar', $branch->id));
        $this->assertFalse($user->hasPermission('usuario.criar', $branch->id));
    }
}
