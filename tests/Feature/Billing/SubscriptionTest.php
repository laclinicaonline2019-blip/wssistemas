<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Mail\BillingNoticeMail;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionInvoice;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\SaasPlan;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Fase 16 — assinatura das clínicas: teste grátis, renovação, régua de cobrança, bloqueio, planos, cancelamento, gateway. */
class SubscriptionTest extends TestCase
{
    private SaasPlan $basic;

    private SaasPlan $pro;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00', 'America/Sao_Paulo'));
        $this->basic = SaasPlan::create(['code' => 'basico', 'name' => 'Básico', 'price_monthly_cents' => 10000, 'price_yearly_cents' => 100000, 'trial_days' => 14,
            'limits' => ['max_users' => 3, 'max_doctors' => 2, 'max_branches' => 1]]);
        $this->pro = SaasPlan::create(['code' => 'pro', 'name' => 'Profissional', 'price_monthly_cents' => 20000, 'price_yearly_cents' => 200000, 'trial_days' => 14, 'limits' => []]);
    }

    private function trialClinic(): array
    {
        return $this->createClinic('Clínica Trial', ['status' => Company::STATUS_TRIAL, 'saas_plan_id' => $this->basic->id, 'email' => 'financeiro@trial.test']);
    }

    private function sub(string $companyId): Subscription
    {
        return Subscription::query()->where('company_id', $companyId)->firstOrFail();
    }

    private function runBilling(): array
    {
        return app(SubscriptionService::class)->run();
    }

    public function test_trial_renewal_dunning_lock_and_payment_reactivation(): void
    {
        $clinic = $this->trialClinic();
        $s = $this->sub($clinic['company']->id);
        $this->assertSame(['trialing', '2026-10-15', $this->basic->id], [$s->status, $s->trial_ends_on->toDateString(), $s->saas_plan_id]);

        // 7 dias antes do fim do teste: fatura emitida (uma só, mesmo rodando várias vezes), cobrança MOCK e e-mail.
        $this->travelTo(CarbonImmutable::parse('2026-10-08 09:00', 'America/Sao_Paulo'));
        $this->runBilling();
        $this->runBilling();
        $inv = SubscriptionInvoice::query()->sole();
        $this->assertSame(['renewal', 10000, '2026-10-15', '2026-10-15', '2026-11-15', 'mock'], [$inv->kind, $inv->amount_cents, $inv->due_date->toDateString(),
            $inv->period_start->toDateString(), $inv->period_end->toDateString(), $inv->provider]);
        $this->assertNotNull($inv->payment_url);
        Mail::assertSent(BillingNoticeMail::class, fn ($m) => $m->hasTo('financeiro@trial.test') && str_contains($m->text, 'R$ 100,00'));
        $this->actingAs($clinic['admin']->fresh())->get(route('home'))->assertOk()->assertSee('teste grátis termina em 7 dia(s)');

        // Teste acabou sem pagamento: "em atraso", acesso normal com aviso.
        $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00', 'America/Sao_Paulo'));
        $this->runBilling();
        $this->assertSame(['past_due', Company::STATUS_ACTIVE], [$this->sub($clinic['company']->id)->status, $clinic['company']->fresh()->status]);
        $this->actingAs($clinic['admin']->fresh())->get(route('home'))->assertOk()->assertSee('Pagamento em atraso');

        // Vencida há mais de 10 dias: bloqueada.
        $this->travelTo(CarbonImmutable::parse('2026-10-26 09:00', 'America/Sao_Paulo'));
        $this->runBilling();
        $this->assertSame(['suspended', Company::STATUS_SUSPENDED], [$this->sub($clinic['company']->id)->status, $clinic['company']->fresh()->status]);

        // Equipe não entra; o administrador entra, mas só na área da assinatura (API: 402).
        $reception = $this->userWithRole($clinic['company'], 'recepcao');
        $this->post('/logout');
        $this->post('/login', ['email' => $reception->email, 'password' => self::PASSWORD])->assertSessionHasErrors();
        $this->assertGuest();
        $this->post('/login', ['email' => $clinic['admin']->email, 'password' => self::PASSWORD])->assertRedirect();
        $this->assertAuthenticatedAs($clinic['admin']);
        $this->get(route('patients.index'))->assertRedirect(route('billing.index'));
        $this->get(route('billing.index'))->assertOk()->assertSee('Bloqueada por falta de pagamento')->assertSee('Pagar (PIX, boleto ou cartão)');
        $this->post('/logout');
        $this->app['auth']->forgetGuards();
        $this->withToken($this->apiToken($clinic['admin']))->getJson('/api/v1/patients')->assertStatus(402);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        // "Já paguei" sem pagamento: continua bloqueada. Pagamento (MOCK) confirmado → reativa na hora e renova o período.
        $this->actingAs($clinic['admin']->fresh())->post(route('billing.check', $inv))->assertSessionHas('warning');
        $this->actingAs($clinic['admin']->fresh())->get(route('billing.mock_pay', $inv->provider_charge_id))->assertRedirect(route('billing.index'));
        $s = $this->sub($clinic['company']->id);
        $this->assertSame(['active', '2026-10-15', '2026-11-15', 'paid'], [$s->status, $s->current_period_start->toDateString(), $s->current_period_end->toDateString(), $inv->fresh()->status]);
        $this->assertSame(Company::STATUS_ACTIVE, $clinic['company']->fresh()->status);
        $this->actingAs($clinic['admin']->fresh())->get(route('patients.index'))->assertOk();
        $this->assertTrue(DB::table('audit_logs')->where('action', 'billing.suspended')->exists());
        $this->assertTrue(DB::table('audit_logs')->where('action', 'billing.invoice_paid')->exists());
    }

    public function test_upgrade_prorates_downgrade_waits_for_renewal_and_limits_are_checked(): void
    {
        $clinic = $this->createClinic('Clínica Ativa', ['saas_plan_id' => $this->basic->id]);
        $s = $this->sub($clinic['company']->id);
        $s->forceFill(['saas_plan_id' => $this->basic->id, 'status' => 'active', 'current_period_start' => '2026-10-01', 'current_period_end' => '2026-10-31'])->save(); // 30 dias
        $admin = $clinic['admin'];

        // Upgrade no dia 16: faltam 15 de 30 dias → (200 − 100) × 15/30 = R$ 50,00; plano novo vale na hora.
        $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00', 'America/Sao_Paulo'));
        $this->actingAs($admin->fresh())->post(route('billing.plan'), ['plan_id' => $this->pro->id, 'cycle' => 'monthly'])->assertSessionHas('success', fn ($m) => str_contains($m, 'R$ 50,00'));
        $up = SubscriptionInvoice::query()->where('kind', 'upgrade')->sole();
        $this->assertSame([5000, '2026-10-19'], [$up->amount_cents, $up->due_date->toDateString()]);
        $this->assertSame($this->pro->id, $clinic['company']->fresh()->saas_plan_id);

        // Downgrade: agendado; a renovação já sai no plano novo; ao pagar, aplica e limpa o agendamento.
        $this->actingAs($admin->fresh())->post(route('billing.plan'), ['plan_id' => $this->basic->id, 'cycle' => 'monthly'])->assertSessionHas('success', fn ($m) => str_contains($m, 'próxima renovação'));
        $s = $this->sub($clinic['company']->id);
        $this->assertSame([$this->pro->id, $this->basic->id], [$s->saas_plan_id, $s->pending_plan_id]);
        $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00', 'America/Sao_Paulo'));
        $this->runBilling();
        $renewal = SubscriptionInvoice::query()->where('kind', 'renewal')->sole();
        $this->assertSame([10000, $this->basic->id], [$renewal->amount_cents, $renewal->saas_plan_id]);
        app(SubscriptionService::class)->markPaid($renewal, 10000, 'manual', $admin, 'teste');
        $s = $this->sub($clinic['company']->id);
        $this->assertSame([$this->basic->id, null, '2026-11-30'], [$s->saas_plan_id, $s->pending_plan_id, $s->current_period_end->toDateString()]);

        // Downgrade que não cabe no uso atual é recusado.
        foreach (range(1, 3) as $i) {
            $this->userWithRole($clinic['company'], 'recepcao');
        }
        $tiny = SaasPlan::create(['code' => 'mini', 'name' => 'Mini', 'price_monthly_cents' => 5000, 'limits' => ['max_users' => 2]]);
        $this->actingAs($admin->fresh())->post(route('billing.plan'), ['plan_id' => $tiny->id, 'cycle' => 'monthly'])->assertSessionHas('error', fn ($m) => str_contains($m, 'permite 2 usuários ativos'));

        // Permissão: recepção não gerencia assinatura.
        $this->actingAs($this->userWithRole($clinic['company'], 'recepcao'))->get(route('billing.index'))->assertForbidden();
    }

    public function test_cancel_at_period_end_keeps_data_and_can_be_undone(): void
    {
        $clinic = $this->createClinic('Clínica Cancela', ['saas_plan_id' => $this->basic->id]);
        $s = $this->sub($clinic['company']->id);
        $s->forceFill(['saas_plan_id' => $this->basic->id, 'current_period_start' => '2026-10-01', 'current_period_end' => '2026-11-01'])->save();
        $admin = $clinic['admin'];

        $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00', 'America/Sao_Paulo'));
        $this->runBilling();
        $this->assertSame(1, SubscriptionInvoice::query()->where('status', 'open')->count());
        $this->actingAs($admin->fresh())->post(route('billing.cancel'), ['reason' => 'Mudança de sistema'])->assertSessionHas('success');
        $this->assertSame(['void', true], [SubscriptionInvoice::query()->value('status'), $this->sub($clinic['company']->id)->cancel_at_period_end]);
        $this->actingAs($admin->fresh())->post(route('billing.resume'))->assertSessionHas('success');
        $this->runBilling();
        $this->assertSame(1, SubscriptionInvoice::query()->where('status', 'open')->count()); // nova fatura após desfazer
        $this->actingAs($admin->fresh())->post(route('billing.cancel'), ['reason' => 'Agora sim'])->assertSessionHas('success');

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'America/Sao_Paulo'));
        $this->runBilling();
        $this->assertSame(['cancelled', Company::STATUS_CANCELLED], [$this->sub($clinic['company']->id)->status, $clinic['company']->fresh()->status]);
        $this->assertSame(1, DB::table('branches')->where('company_id', $clinic['company']->id)->count()); // nada apagado
        $this->post('/logout');
        $this->post('/login', ['email' => $admin->email, 'password' => self::PASSWORD])->assertSessionHasErrors();
    }

    public function test_asaas_gateway_customer_charge_webhook_confirmation_and_platform_pages(): void
    {
        config(['billing.provider' => 'asaas', 'billing.asaas.api_key' => 'aact_platform_key', 'billing.asaas.webhook_token' => 'whk-plataforma']);
        $paid = false;
        $value = 100.00;
        Http::fake(function (HttpRequest $r) use (&$paid, &$value) {
            return match (true) {
                str_contains($r->url(), '/customers') && $r->method() === 'GET' => Http::response(['data' => []]),
                str_contains($r->url(), '/customers') => Http::response(['id' => 'cus_123']),
                str_ends_with($r->url(), '/payments') => Http::response(['id' => 'pay_999', 'invoiceUrl' => 'https://sandbox.asaas.com/i/pay_999']),
                str_contains($r->url(), '/payments/pay_999') => Http::response(['id' => 'pay_999', 'status' => $paid ? 'RECEIVED' : 'PENDING', 'value' => $value]),
                default => Http::response([], 404),
            };
        });

        $clinic = $this->trialClinic();
        $this->travelTo(CarbonImmutable::parse('2026-10-09 09:00', 'America/Sao_Paulo'));
        $this->runBilling();
        $inv = SubscriptionInvoice::query()->sole();
        $this->assertSame(['asaas', 'pay_999', 'https://sandbox.asaas.com/i/pay_999'], [$inv->provider, $inv->provider_charge_id, $inv->payment_url]);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/customers') && $r->method() === 'POST' && $r['cpfCnpj'] === $clinic['company']->document && $r->hasHeader('access_token', 'aact_platform_key'));
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/payments') && $r['customer'] === 'cus_123' && $r['value'] == 100 && $r['billingType'] === 'UNDEFINED' && $r['externalReference'] === $inv->id);
        $this->assertSame('cus_123', $this->sub($clinic['company']->id)->gateway_customer_id);

        $hook = fn (string $token, string $id = 'evt_1') => $this->postJson(route('billing.webhook'), ['id' => $id, 'event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_999', 'status' => 'RECEIVED']], ['asaas-access-token' => $token]);
        $hook('errado')->assertUnauthorized();
        // Webhook diz pago, mas a API ainda não: nada muda.
        $hook('whk-plataforma', 'evt_0')->assertOk()->assertSee('sem pagamento confirmado');
        $this->assertSame('open', $inv->fresh()->status);
        // Valor menor que a fatura: não baixa (vai para conferência).
        $paid = true;
        $value = 50.00;
        $hook('whk-plataforma', 'evt_half')->assertOk();
        $this->assertSame('open', $inv->fresh()->status);
        $this->assertStringContainsString('menor que a fatura', $inv->fresh()->notes);
        $value = 100.00;
        $hook('whk-plataforma')->assertOk()->assertSee('paga');
        $hook('whk-plataforma')->assertOk()->assertSee('duplicado');
        $this->assertSame(['paid', 'gateway', 'active'], [$inv->fresh()->status, $inv->fresh()->paid_via, $this->sub($clinic['company']->id)->status]);

        // Plataforma: MRR, prorrogação de teste, baixa manual; clínica não acessa.
        $super = $this->context()->runAsSystem(function () {
            $u = new User(['name' => 'Root', 'email' => 'root@example.test', 'password' => self::PASSWORD]);
            $u->is_super_admin = true;
            $u->save();

            return $u;
        });
        $this->actingAs($super)->get(route('platform.billing.index'))->assertOk()->assertSee('R$ 100,00')->assertSee('Clínica Trial');
        $other = $this->trialClinic();
        $this->actingAs($super)->post(route('platform.billing.trial', $other['company']), ['days' => 10])->assertSessionHas('success');
        $this->assertSame('2026-11-02', $this->sub($other['company']->id)->trial_ends_on->toDateString()); // fim do teste 23/10 + 10 dias
        $this->actingAs($clinic['admin']->fresh())->get(route('platform.billing.index'))->assertForbidden();
    }
}
