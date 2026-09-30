<?php

namespace Tests\Feature\Web;

use App\Modules\Identity\Models\User;
use Tests\TestCase;

/** Smoke test das telas: renderizam com dados reais e respeitam permissões. */
class WebPagesTest extends TestCase
{
    public function test_company_admin_can_open_every_administration_page(): void
    {
        ['admin' => $admin, 'branch' => $branch, 'company' => $company] = $this->createClinic();
        $other = $this->userWithRole($company, 'recepcao', $branch);
        $this->actingAs($admin);

        $this->get('/')->assertOk()->assertSee('Unidades');
        $this->get(route('branches.index'))->assertOk()->assertSee($branch->name);
        $this->get(route('branches.create'))->assertOk();
        $this->get(route('branches.edit', $branch))->assertOk();
        $this->get(route('users.index'))->assertOk()->assertSee($other->email);
        $this->get(route('users.create'))->assertOk();
        $this->get(route('users.edit', $other))->assertOk()->assertSee('Perfis de acesso');
        $this->get(route('roles.index'))->assertOk()->assertSee('Administrador da empresa');
        $this->get(route('roles.edit', $this->roleId($company, 'medico')))->assertOk()->assertSee('prontuario.editar');
        $this->get(route('roles.create'))->assertOk();
        $this->get(route('audit.index'))->assertOk();
        $this->get(route('company.edit'))->assertOk();
        $this->get(route('account.security'))->assertOk();
        $this->get(route('print.test', 'a4'))->assertOk()->assertSee('Página de teste de impressão');
        $this->get(route('print.test', 'thermal'))->assertOk()->assertSee('A000');
    }

    public function test_web_forms_create_branch_user_and_roles(): void
    {
        ['admin' => $admin, 'branch' => $branch, 'company' => $company] = $this->createClinic();
        $this->actingAs($admin);

        $this->post(route('branches.store'), ['name' => 'Unidade Norte', 'code' => 'norte', 'document' => '11.222.333/0001-81', 'zip_code' => '01000-000'])
            ->assertRedirect(route('branches.index'));
        $this->assertDatabaseHas('branches', ['code' => 'NORTE', 'zip_code' => '01000000', 'document' => '11222333000181']);

        $this->post(route('users.store'), [
            'name' => 'Nova Médica', 'email' => 'NOVA@Example.test',
            'password' => 'Senha@Forte123', 'password_confirmation' => 'Senha@Forte123',
            'roles' => [
                ['role_id' => $this->roleId($company, 'medico'), 'branch_id' => $branch->id],
                ['role_id' => '', 'branch_id' => ''], // linha vazia do formulário
            ],
        ])->assertRedirect();

        $user = User::where('email', 'nova@example.test')->firstOrFail();
        $this->assertTrue($user->hasPermission('prontuario.editar', $branch->id));

        $this->put(route('users.roles', $user), ['roles' => [
            ['role_id' => $this->roleId($company, 'recepcao'), 'branch_id' => ''],
        ]])->assertSessionHas('success');
        $this->assertTrue($user->fresh()->hasPermission('agenda.criar', '__company_wide__'));
        $this->assertFalse($user->fresh()->hasPermission('prontuario.editar', $branch->id));

        $this->put(route('company.update'), ['trade_name' => 'Nome Novo', 'settings' => ['print' => ['thermal_width_mm' => 58], 'security' => ['require_2fa' => '0']]])
            ->assertSessionHas('success');
        $this->assertSame(58, (int) $company->fresh()->setting('print.thermal_width_mm'));
    }

    public function test_menu_and_pages_follow_permissions(): void
    {
        ['company' => $company, 'branch' => $branch] = $this->createClinic();
        $recep = $this->userWithRole($company, 'recepcao', $branch);
        $this->actingAs($recep);

        $this->get('/')->assertOk()->assertDontSee(route('users.index'));
        $this->get(route('users.index'))->assertForbidden();
        $this->get(route('roles.index'))->assertForbidden();
        $this->get(route('company.edit'))->assertForbidden();
    }

    public function test_branch_switcher_only_accepts_allowed_branches(): void
    {
        ['company' => $company, 'branch' => $hq] = $this->createClinic();
        $sul = $this->createBranch($company, 'Sul');
        $gestor = $this->userWithRole($company, 'admin_filial', $sul);
        $this->actingAs($gestor);

        $this->from('/')->post(route('context.branch'), ['branch_id' => $hq->id])->assertForbidden();
        $this->from('/')->post(route('context.branch'), ['branch_id' => $sul->id])->assertRedirect('/');
        $this->from('/')->post(route('context.branch'), ['branch_id' => ''])->assertForbidden();
    }

    public function test_platform_pages_render_for_super_admin(): void
    {
        ['company' => $company] = $this->createClinic();
        $super = $this->context()->runAsSystem(function () {
            $u = new User(['name' => 'Root', 'email' => 'root@example.test', 'password' => self::PASSWORD]);
            $u->is_super_admin = true;
            $u->save();

            return $u;
        });
        $this->actingAs($super);

        $this->get(route('platform.dashboard'))->assertOk()->assertSee('Saúde do sistema');
        $this->get(route('platform.companies.index'))->assertOk()->assertSee($company->trade_name);
        $this->get(route('platform.companies.create'))->assertOk();
        $this->get(route('platform.companies.show', $company))->assertOk();
        $this->get(route('platform.plans.index'))->assertOk();

        $this->post(route('platform.companies.store'), [
            'legal_name' => 'Web Ltda', 'trade_name' => 'Web', 'document' => '11222333000181', 'headquarters_name' => 'Matriz',
            'admin_name' => 'Adm', 'admin_email' => 'adm.web@example.test', 'admin_password' => 'Senha@Forte123',
        ])->assertRedirect();
        $this->assertDatabaseHas('companies', ['trade_name' => 'Web']);
    }
}
