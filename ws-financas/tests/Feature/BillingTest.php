<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Billing\BillingService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BillingTest extends TestCase
{
    public function test_teste_gratis_inadimplencia_suspensao_e_reativacao_por_webhook(): void
    {
        config(['services.asaas.mode' => 'asaas', 'services.asaas.api_key' => 'chave', 'services.asaas.sandbox' => true]);
        Http::fake([
            'api-sandbox.asaas.com/v3/customers?*' => Http::response(['data' => []]),
            'api-sandbox.asaas.com/v3/customers' => Http::response(['id' => 'cus_1']),
            'api-sandbox.asaas.com/v3/subscriptions' => Http::response(['id' => 'sub_1']),
            'api-sandbox.asaas.com/v3/subscriptions/sub_1/payments*' => Http::response(['data' => [['id' => 'pay_1', 'subscription' => 'sub_1', 'value' => 69.9, 'dueDate' => now()->subDays(20)->toDateString(), 'status' => 'OVERDUE']]]),
            'api-sandbox.asaas.com/v3/payments/pay_1' => Http::response(['id' => 'pay_1', 'subscription' => 'sub_1', 'value' => 69.9, 'dueDate' => now()->subDays(20)->toDateString(), 'status' => 'RECEIVED', 'billingType' => 'PIX', 'paymentDate' => now()->toDateString()]),
        ]);
        [$a, $u, [$c]] = $this->makeClient('profissional');
        $a->update(['document' => '11222333000181']);
        $this->assertSame('trialing', $a->subscription->status);
        $this->actingAs($u)->post('/app/assinatura', ['plan' => 'profissional', 'cycle' => 'monthly'])->assertRedirect();
        $sub = Subscription::query()->first();
        $this->assertSame('sub_1', $sub->gateway_subscription_id);

        // teste acabou, cobrança vencida há mais que a carência → suspensa
        $sub->update(['trial_ends_at' => now()->subDays(20)]);
        $this->ctx()->runAsSystem(fn () => app(BillingService::class)->run());
        $this->assertSame('suspended', $sub->fresh()->status);
        $this->assertSame('suspended', $a->fresh()->status);

        // suspenso: consulta liberada, novos lançamentos bloqueados (dados preservados)
        $this->actingAs($u)->getJson('/app/api/mes?empresa='.$c->id)->assertOk();
        $this->actingAs($u)->postJson('/app/api/lancamentos', ['empresa' => $c->id, 'tipo' => 'despesa', 'desc' => 'x', 'valor' => 1, 'data' => '2026-11-01'])->assertStatus(402);

        // webhook sem token → 401
        $this->postJson('/webhooks/asaas', ['id' => 'evt_1', 'event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_1']])->assertUnauthorized();
        // webhook autenticado → confirma pela API e reativa
        $h = ['asaas-access-token' => 'token-asaas-teste'];
        $this->postJson('/webhooks/asaas', ['id' => 'evt_1', 'event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_1']], $h)->assertOk();
        $this->postJson('/webhooks/asaas', ['id' => 'evt_1', 'event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_1']], $h)->assertJson(['duplicado' => true]);
        $this->assertSame('active', $sub->fresh()->status);
        $this->assertSame('active', $a->fresh()->status);
        $this->assertSame('paid', Invoice::query()->where('gateway_payment_id', 'pay_1')->value('status'));
        $this->assertSame(1, WebhookEvent::query()->count());
        $this->actingAs($u)->postJson('/app/api/lancamentos', ['empresa' => $c->id, 'tipo' => 'despesa', 'desc' => 'x', 'valor' => 1, 'data' => '2026-11-01'])->assertCreated();
    }

    public function test_gateway_simulado_baixa_manual_e_historico(): void
    {
        [$a, $u] = $this->makeClient('basico');
        $this->actingAs($u)->post('/app/assinatura', ['plan' => 'profissional', 'cycle' => 'yearly'])->assertRedirect();
        $inv = Invoice::query()->firstOrFail();
        $this->assertSame(69900, $inv->amount_cents);
        $owner = User::query()->create(['name' => 'Dono', 'email' => 'dono@t.local', 'password' => 'Senha@Forte123', 'is_platform_admin' => true]);
        $this->actingAs($owner)->post('/admin/faturas/'.$inv->id.'/baixa', ['note' => 'Pix recebido fora do Asaas'])->assertRedirect();
        $sub = Subscription::query()->first();
        $this->assertSame('active', $sub->status);
        $this->assertGreaterThan(5, SubscriptionEvent::query()->count() + 3);
        $this->actingAs($owner)->get('/admin')->assertOk()->assertSee('MRR');
        $this->actingAs($owner)->get('/admin/clientes/'.$a->id)->assertOk();
    }

    public function test_limites_do_plano(): void
    {
        [$a, $u] = $this->makeClient('basico');
        $this->actingAs($u)->post('/app/empresas', ['name' => 'Segunda', 'person_type' => 'pj'])->assertSessionHas('error');
        $this->assertSame(1, Company::withoutGlobalScopes()->count());
        $this->actingAs($u)->post('/app/usuarios', ['name' => 'Fulano', 'email' => 'f@t.local', 'role' => 'financeiro'])->assertSessionHas('error');
    }

    public function test_cancelamento_agenda_exclusao_lgpd(): void
    {
        [$a, $u] = $this->makeClient('profissional');
        $this->actingAs($u)->post('/app/assinatura/cancelar', ['confirm' => 'CANCELAR', 'password' => 'Senha@Forte123'])->assertSessionHas('ok');
        $a->refresh();
        $this->assertSame('canceled', $a->status);
        $this->assertNotNull($a->purge_after);
        // ainda pode consultar/exportar
        $this->actingAs($u)->get('/app/privacidade/exportar')->assertOk();
        // após o prazo, os dados são eliminados
        $a->update(['purge_after' => now()->subDay()]);
        $this->artisan('wsf:retention:run')->assertSuccessful();
        $this->assertSame(0, Company::withoutGlobalScopes()->where('account_id', $a->id)->count());
        $this->assertSame('Cliente excluído', $a->fresh()->name);
    }
}
