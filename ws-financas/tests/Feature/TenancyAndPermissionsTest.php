<?php

namespace Tests\Feature;

use App\Core\Tenancy\CrossTenantViolation;
use App\Core\Tenancy\TenantContextMissing;
use App\Models\Entry;
use App\Services\FinanceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Isolamento entre clientes e empresas + permissões verificadas no servidor. */
class TenancyAndPermissionsTest extends TestCase
{
    private function entryFor($account, $company): Entry
    {
        return $this->inAccount($account, fn () => app(FinanceService::class)->create($company, ['tipo' => 'despesa', 'desc' => 'Luz', 'valor_cents' => 10000, 'data' => '2026-11-10'])[0]);
    }

    public function test_cliente_nao_ve_nem_altera_dados_de_outro_cliente(): void
    {
        [$a, $ua, [$ca]] = $this->makeClient();
        [$b, , [$cb]] = $this->makeClient();
        $eb = $this->entryFor($b, $cb);

        $this->actingAs($ua)->getJson('/app/api/mes?empresa='.$cb->id)->assertStatus(422);
        $this->actingAs($ua)->putJson('/app/api/lancamentos/'.$eb->id, ['valor' => 1])->assertNotFound();
        $this->actingAs($ua)->deleteJson('/app/api/lancamentos/'.$eb->id)->assertNotFound();
        $this->actingAs($ua)->postJson('/app/api/lancamentos', ['empresa' => $cb->id, 'tipo' => 'despesa', 'desc' => 'x', 'valor' => 10, 'data' => '2026-11-01'])->assertStatus(422);
        $this->actingAs($ua)->getJson('/app/api/mes?empresa='.$ca->id)->assertOk()->assertJsonMissing(['desc' => 'Luz']);
        $this->assertSame(10000, Entry::withoutGlobalScopes()->find($eb->id)->amount_cents);
    }

    public function test_consulta_sem_contexto_falha_fechada(): void
    {
        $this->expectException(TenantContextMissing::class);
        Entry::query()->count();
    }

    public function test_banco_impede_vincular_lancamento_a_empresa_de_outro_cliente(): void
    {
        [$a, , [$ca]] = $this->makeClient();
        [$b, , [$cb]] = $this->makeClient();
        $this->expectException(QueryException::class);
        DB::table('entries')->insert(['id' => (string) Str::ulid(), 'account_id' => $a->id, 'company_id' => $cb->id, 'type' => 'despesa',
            'description' => 'x', 'amount_cents' => 1, 'competence' => '2026-11', 'due_date' => '2026-11-01', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_contador_so_acessa_empresas_autorizadas(): void
    {
        [$a, , [$c1, $c2]] = $this->makeClient('empresarial', ['Empresa A', 'Empresa B']);
        $e2 = $this->entryFor($a, $c2);
        $this->entryFor($a, $c1);
        $contador = $this->addMember($a, 'contador', [$c1->id]);

        $this->actingAs($contador)->getJson('/app/api/mes?empresa='.$c1->id)->assertOk()->assertJsonFragment(['desc' => 'Luz']);
        $this->actingAs($contador)->getJson('/app/api/mes?empresa='.$c2->id)->assertStatus(422);
        $this->actingAs($contador)->getJson('/app/api/mes?empresa=todas')->assertOk()->assertJsonMissing(['id' => $e2->id]);
        $this->actingAs($contador)->postJson('/app/api/lancamentos', ['empresa' => $c1->id, 'tipo' => 'despesa', 'desc' => 'x', 'valor' => 10, 'data' => '2026-11-01'])->assertForbidden();
        $this->actingAs($contador)->get('/app/usuarios')->assertForbidden();
        $this->actingAs($contador)->get('/app/relatorios/exportar?empresa='.$c1->id)->assertOk();
    }

    public function test_criacao_em_empresa_nao_autorizada_e_recusada_no_modelo(): void
    {
        [$a, , [$c1, $c2]] = $this->makeClient('empresarial', ['A', 'B']);
        $this->expectException(CrossTenantViolation::class);
        $this->ctx()->runFor($a->id, fn () => Entry::query()->create(['company_id' => $c2->id, 'type' => 'despesa', 'description' => 'x', 'amount_cents' => 1, 'competence' => '2026-11', 'due_date' => '2026-11-01']), [$c1->id]);
    }

    public function test_perfis(): void
    {
        [$a, , [$c]] = $this->makeClient();
        $consulta = $this->addMember($a, 'consulta', [], true);
        $fin = $this->addMember($a, 'financeiro', [], true);
        $payload = ['empresa' => $c->id, 'tipo' => 'despesa', 'desc' => 'Água', 'valor' => '95,00', 'data' => '2026-11-18'];

        $this->actingAs($consulta)->getJson('/app/api/mes?empresa='.$c->id)->assertOk();
        $this->actingAs($consulta)->postJson('/app/api/lancamentos', $payload)->assertForbidden();
        $this->actingAs($fin)->postJson('/app/api/lancamentos', $payload)->assertCreated()->assertJsonPath('itens.0.valor', 95);
        $this->actingAs($fin)->get('/app/usuarios')->assertForbidden();
        $this->actingAs($fin)->get('/app/assinatura')->assertForbidden();
        $this->actingAs($fin)->get('/admin')->assertNotFound();
    }

    public function test_parcelado_fixo_e_exclusao_em_grupo(): void
    {
        [$a, $u, [$c]] = $this->makeClient();
        $r = $this->actingAs($u)->postJson('/app/api/lancamentos', ['empresa' => $c->id, 'tipo' => 'despesa', 'desc' => 'Geladeira', 'valor' => '1.000,00', 'data' => '2026-11-10', 'repetir' => 3, 'modo' => 'parcelada'])->assertCreated();
        $this->assertSame([333.34, 333.33, 333.33], array_column($r->json('itens'), 'valor'));
        $this->assertSame('1/3', $r->json('itens.0.parcela'));
        $id = $r->json('itens.1.id');
        $this->actingAs($u)->deleteJson('/app/api/lancamentos/'.$id.'?escopo=grupo')->assertOk()->assertJson(['removidos' => 2]);
        $this->assertSame(1, $this->inAccount($a, fn () => Entry::query()->count()));
    }

    public function test_consolidado_exige_plano(): void
    {
        [, $u] = $this->makeClient('basico');
        $this->actingAs($u)->getJson('/app/api/mes?empresa=todas')->assertStatus(422);
    }
}
