<?php

namespace Tests\Feature\Audit;

use App\Core\Audit\AuditChain;
use App\Core\Health\HealthChecker;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    public function test_changes_record_user_ip_old_and_new_values(): void
    {
        ['admin' => $admin, 'branch' => $branch] = $this->createClinic();

        $this->api($admin)->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->patchJson("/api/v1/branches/{$branch->id}", ['name' => 'Matriz Nova'])->assertOk();

        $log = DB::table('audit_logs')->where('action', 'branch.updated')->latest('id')->first();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('203.0.113.9', $log->ip_address);
        $this->assertSame($branch->name, json_decode($log->old_values, true)['name']);
        $this->assertSame('Matriz Nova', json_decode($log->new_values, true)['name']);
        $this->assertNotNull($log->request_id);
    }

    public function test_secrets_never_reach_the_audit_trail(): void
    {
        ['admin' => $admin] = $this->createClinic();

        $this->api($admin)->postJson('/api/v1/users', [
            'name' => 'Teste', 'email' => 'teste@example.test',
            'password' => 'Segredo@Unico987', 'password_confirmation' => 'Segredo@Unico987',
        ])->assertCreated();

        $dump = DB::table('audit_logs')->get()->toJson();
        $this->assertStringNotContainsString('Segredo@Unico987', $dump);
        $this->assertStringNotContainsString('$argon2id$', $dump);
    }

    public function test_audit_log_is_append_only_in_the_database_when_triggers_are_available(): void
    {
        if (! app(HealthChecker::class)->auditTriggersActive()) {
            $this->markTestSkipped('Banco sem privilégio para triggers (ex.: hospedagem compartilhada) — coberto pela cadeia HMAC.');
        }

        $this->createClinic();
        $id = DB::table('audit_logs')->value('id');

        try {
            DB::table('audit_logs')->where('id', $id)->update(['action' => 'adulterado']);
            $this->fail('UPDATE deveria ser bloqueado');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->expectException(QueryException::class);
        DB::table('audit_logs')->where('id', $id)->delete();
    }

    public function test_hash_chain_is_intact_after_normal_operation(): void
    {
        ['admin' => $admin, 'branch' => $branch] = $this->createClinic();
        $this->api($admin)->patchJson("/api/v1/branches/{$branch->id}", ['name' => 'Ação com acentuação & "aspas"'])->assertOk();

        $this->artisan('aivexa:audit:verify')->assertSuccessful();

        $chain = app(AuditChain::class);
        foreach ($chain->scopes() as $scope) {
            $this->assertTrue($chain->verify($scope)['ok'], "cadeia {$scope}");
        }
    }

    public function test_hash_chain_detects_tampering_and_deletion(): void
    {
        if (app(HealthChecker::class)->auditTriggersActive()) {
            $this->markTestSkipped('Com triggers ativos o banco impede a adulteração (testado acima).');
        }

        ['company' => $company] = $this->createClinic();
        $chain = app(AuditChain::class);
        $ids = DB::table('audit_logs')->where('company_id', $company->id)->orderBy('id')->pluck('id');

        DB::table('audit_logs')->where('id', $ids[1])->update(['action' => 'adulterado']);
        $result = $chain->verify($company->id);
        $this->assertFalse($result['ok']);
        $this->assertSame($ids[1], $result['broken_at']);

        DB::table('audit_logs')->where('id', $ids[1])->delete();
        $this->assertFalse($chain->verify($company->id)['ok']);

        $this->artisan('aivexa:audit:verify')->assertFailed();
    }

    public function test_audit_listing_filters_and_export(): void
    {
        ['admin' => $admin, 'company' => $company, 'branch' => $branch] = $this->createClinic();
        $this->postJson('/api/v1/auth/token', ['email' => $admin->email, 'password' => 'errada', 'device_name' => 't']);

        $this->api($admin)->getJson('/api/v1/audit-logs?action=auth.login.failed')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.result', 'failure');

        $recep = $this->userWithRole($company, 'recepcao', $branch);
        $this->actingAs($recep)->get(route('audit.export'))->assertForbidden();

        $response = $this->actingAs($admin)->get(route('audit.export'));
        $response->assertOk();
        $this->assertStringContainsString('data_hora', $response->streamedContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'audit.exported', 'user_id' => $admin->id]);
    }
}
