<?php

namespace Tests\Feature\Platform;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    private User $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->super = $this->context()->runAsSystem(function () {
            $u = new User(['name' => 'Root', 'email' => 'root@example.test', 'password' => self::PASSWORD]);
            $u->is_super_admin = true;
            $u->save();

            return $u;
        });
    }

    public function test_super_admin_provisions_a_complete_clinic(): void
    {
        $id = $this->api($this->super)->postJson('/api/v1/platform/companies', [
            'legal_name' => 'Clínica Nova Ltda', 'trade_name' => 'Clínica Nova', 'document' => '11.222.333/0001-81',
            'headquarters_name' => 'Matriz', 'admin_name' => 'Gestora', 'admin_email' => 'gestora@example.test',
            'admin_password' => 'Senha@Forte123',
        ])->assertCreated()->assertJsonPath('data.status', 'trial')->json('data.id');

        $this->assertSame(1, DB::table('branches')->where('company_id', $id)->where('is_headquarters', true)->count());
        $this->assertSame(6, DB::table('roles')->where('company_id', $id)->count());
        $admin = User::where('email', 'gestora@example.test')->first();
        $this->assertTrue($admin->must_change_password);
        $this->assertTrue($admin->hasPermission('usuario.criar', '__company_wide__'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'company.provisioned', 'company_id' => $id]);
    }

    public function test_invalid_cnpj_is_rejected(): void
    {
        $this->api($this->super)->postJson('/api/v1/platform/companies', [
            'legal_name' => 'X', 'trade_name' => 'X', 'document' => '11.222.333/0001-82',
            'headquarters_name' => 'Matriz', 'admin_name' => 'G', 'admin_email' => 'g@example.test', 'admin_password' => 'Senha@Forte123',
        ])->assertUnprocessable()->assertJsonValidationErrors('document');
    }

    public function test_suspending_a_company_revokes_access_immediately(): void
    {
        ['company' => $company, 'admin' => $admin] = $this->createClinic();
        $tenantToken = $this->apiToken($admin);

        $this->api($this->super)->patchJson("/api/v1/platform/companies/{$company->id}", ['status' => 'suspended'])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($tenantToken)->getJson('/api/v1/branches')->assertUnauthorized();
        $this->assertDatabaseHas('audit_logs', ['action' => 'company.updated', 'company_id' => $company->id]);
    }

    public function test_health_and_metrics(): void
    {
        $this->createClinic();

        $this->api($this->super)->getJson('/api/v1/platform/health')
            ->assertOk()->assertJsonPath('status', 'ok')->assertJsonStructure(['checks' => ['database', 'migrations', 'cache', 'storage', 'queue']]);
        $this->api($this->super)->getJson('/api/v1/platform/metrics')->assertOk()->assertJsonPath('data.companies.active', 1);
    }

    public function test_install_command_is_idempotent(): void
    {
        $args = [
            '--super-admin-name' => 'Outro Root', '--super-admin-email' => 'root2@example.test', '--super-admin-password' => 'Senha@Forte123',
            '--company-legal-name' => 'Instalada Ltda', '--company-trade-name' => 'Instalada', '--company-document' => '11222333000181',
            '--admin-name' => 'Adm', '--admin-email' => 'adm@example.test', '--admin-password' => 'Senha@Forte123',
        ];

        $this->artisan('aivexa:install', $args)->assertSuccessful();
        $this->artisan('aivexa:install', $args)->assertSuccessful();

        // Super Admin já existia (setUp): não duplica; primeira clínica criada uma única vez.
        $this->assertSame(1, DB::table('users')->where('is_super_admin', true)->count());
        $this->assertSame(1, DB::table('companies')->count());
        $this->assertSame(3, DB::table('saas_plans')->count());
        $this->assertDatabaseHas('users', ['email' => 'adm@example.test']);
    }
}
