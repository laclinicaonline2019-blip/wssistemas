<?php

namespace Tests\Feature\Payments;

use App\Modules\Finance\Models\FinancialTransaction;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentGateway;
use App\Modules\Payments\Models\PaymentSplit;
use App\Modules\Payments\Models\SplitRule;
use App\Modules\Payments\Providers\MockProvider;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Scheduling\SchedulingSetup;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use SchedulingSetup;

    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScheduling();
    }

    private function as($user): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->tokens[$user->id] ??= $this->apiToken($user);
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->tokens[$user->id]);
    }

    private function receivable(array $patientAttrs = ['cpf' => '52998224725']): Receivable
    {
        $id = $this->as($this->clinic['admin'])->postJson('/api/v1/appointments', $this->bookPayload($this->patient('Paciente Pagante', $patientAttrs), '08:00'))->json('data.id');
        $this->as($this->clinic['admin'])->postJson("/api/v1/appointments/{$id}/arrive")->assertCreated();

        return $this->tenant(fn () => Receivable::query()->where('appointment_id', $id)->firstOrFail());
    }

    private function tenant(\Closure $fn): mixed
    {
        return $this->context()->runFor($this->company()->id, $fn);
    }

    private function gateway(string $provider = 'mock', array $credentials = [], string $mode = 'sandbox'): PaymentGateway
    {
        return $this->tenant(fn () => app(PaymentService::class)->saveGateway($this->clinic['admin'],
            ['provider' => $provider, 'mode' => $mode, 'credentials' => $credentials, 'is_default' => true]));
    }

    private function charge(Receivable $r, string $type = 'pix', ?string $key = null): TestResponse
    {
        return $this->as($this->clinic['admin'])->postJson("/api/v1/receivables/{$r->id}/charges", ['billing_type' => $type, 'amount_cents' => $r->balanceCents(), 'due_date' => '2026-10-08'],
            ['Idempotency-Key' => $key ?? 'chave-'.str_repeat('a', 12).$r->id]);
    }

    private function rule(int $percentBp = 6000): void
    {
        $this->tenant(fn () => SplitRule::create(['doctor_id' => $this->doctor->id, 'type' => 'percent', 'value' => $percentBp]));
    }

    public function test_mock_charge_is_idempotent_and_confirmed_only_through_the_webhook_flow(): void
    {
        $gw = $this->gateway();
        $r = $this->receivable();

        $first = $this->charge($r)->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.test_mode', 'MOCK');
        $this->charge($r)->assertOk()->assertJsonPath('data.id', $first->json('data.id')); // mesma chave → mesma cobrança
        $this->assertSame(1, DB::table('payment_charges')->count());

        $charge = $this->tenant(fn () => PaymentCharge::query()->firstOrFail());
        $this->get(route('payments.public', $charge->public_token))->assertOk()->assertSee('MOCK')->assertSee('R$ 250,00')->assertDontSee('Paciente Pagante');

        $this->actingAs($this->clinic['admin'])->post(route('charges.simulate', $charge), ['outcome' => 'paid'])->assertRedirect()->assertSessionHas('success');

        $charge->refresh();
        $this->assertSame('paid', $charge->status);
        $this->assertSame('paid', $r->fresh()->status);
        $receipt = $this->tenant(fn () => FinancialTransaction::query()->findOrFail($charge->transaction_id));
        $this->assertSame(['in', 'receipt', 'pix', 25000, 'mock', null], [$receipt->direction, $receipt->kind, $receipt->method, $receipt->amount_cents, $receipt->gateway, $receipt->cash_session_id]);
        $this->assertSame(498, DB::table('financial_transactions')->where('kind', 'fee')->value('amount_cents')); // 1,99% simulado
        $this->assertSame(1, DB::table('payment_webhook_events')->where('status', 'processed')->count());

        // Recebimento online não é estornado manualmente (só pelo gateway); tarifa também não.
        $this->as($this->clinic['admin'])->postJson("/api/v1/transactions/{$receipt->id}/reverse", ['reason' => 'Tentativa de estorno manual'])
            ->assertUnprocessable()->assertJsonPath('code', 'use_gateway_refund');

        // Novo aviso de pagamento não duplica a baixa.
        $this->tenant(fn () => app(PaymentService::class)->sync($charge));
        $this->assertSame(1, DB::table('financial_transactions')->where('kind', 'receipt')->count());
    }

    public function test_webhook_requires_token_and_is_idempotent(): void
    {
        $gw = $this->gateway();
        $r = $this->receivable();
        $providerId = $this->charge($r)->json('data.provider_charge_id');
        $url = route('payments.webhook', $gw->id);

        $this->postJson($url, ['id' => 'evt_1', 'charge_id' => $providerId])->assertUnauthorized();
        $this->postJson($url, ['id' => 'evt_1', 'charge_id' => $providerId], ['X-Mock-Token' => 'errado'])->assertUnauthorized();
        $this->postJson(route('payments.webhook', '01ZZZZZZZZZZZZZZZZZZZZZZZZ'), [], ['X-Mock-Token' => 'x'])->assertNotFound();

        app(MockProvider::class)->simulate($providerId, 'paid');
        $this->postJson($url, ['id' => 'evt_1', 'event' => 'PAID', 'charge_id' => $providerId], ['X-Mock-Token' => $gw->webhook_token])->assertOk()->assertJsonPath('message', 'ok');
        $this->postJson($url, ['id' => 'evt_1', 'event' => 'PAID', 'charge_id' => $providerId], ['X-Mock-Token' => $gw->webhook_token])->assertOk()->assertJsonPath('message', 'evento já recebido');
        $this->postJson($url, ['id' => 'evt_2', 'charge_id' => 'mock_pay_inexistente'], ['X-Mock-Token' => $gw->webhook_token])->assertOk()->assertJsonPath('message', 'ignorado');

        $this->assertSame(1, DB::table('financial_transactions')->where('kind', 'receipt')->count());
    }

    public function test_asaas_charge_with_native_split_and_confirmation_by_api_lookup(): void
    {
        $gw = $this->gateway('asaas', ['api_key' => '$aact_teste_123']);
        $this->rule(6000);
        $this->tenant(fn () => $this->doctor->forceFill(['asaas_wallet_id' => 'wallet-medica-1'])->save());
        $r = $this->receivable();
        $status = 'PENDING';

        Http::fake([
            'api-sandbox.asaas.com/v3/customers' => Http::response(['id' => 'cus_001']),
            'api-sandbox.asaas.com/v3/payments' => Http::response(['id' => 'pay_001', 'invoiceUrl' => 'https://sandbox.asaas.com/i/pay_001', 'status' => 'PENDING']),
            'api-sandbox.asaas.com/v3/payments/pay_001/pixQrCode' => Http::response(['encodedImage' => base64_encode('png'), 'payload' => '00020126PIXREAL']),
            'api-sandbox.asaas.com/v3/payments/pay_001' => function () use (&$status) {
                return Http::response(['id' => 'pay_001', 'status' => $status, 'value' => 250.0, 'netValue' => 248.01, 'billingType' => 'PIX', 'paymentDate' => '2026-10-05']);
            },
        ]);

        $this->charge($r)->assertCreated()->assertJsonPath('data.provider_charge_id', 'pay_001')->assertJsonPath('data.test_mode', 'SANDBOX')->assertJsonPath('data.pix_payload', '00020126PIXREAL');
        Http::assertSent(fn (HttpRequest $req) => $req->url() === 'https://api-sandbox.asaas.com/v3/payments' && $req->method() === 'POST'
            && $req['billingType'] === 'PIX' && $req['value'] == 250 && $req['customer'] === 'cus_001'
            && $req['split'] === [['walletId' => 'wallet-medica-1', 'percentualValue' => 60.0]] && $req->hasHeader('access_token', '$aact_teste_123'));

        $hook = fn ($id) => $this->postJson(route('payments.webhook', $gw->id), ['id' => $id, 'event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_001', 'status' => 'RECEIVED']], ['asaas-access-token' => $gw->webhook_token]);

        // Webhook diz "recebido", mas a API ainda diz PENDING → não dá baixa.
        $hook('evt_a')->assertOk();
        $this->assertSame('pending', DB::table('payment_charges')->value('status'));
        $this->assertSame(0, DB::table('financial_transactions')->count());

        $status = 'RECEIVED';
        $hook('evt_b')->assertOk();
        $this->assertSame('paid', DB::table('payment_charges')->value('status'));
        $this->assertSame(199, DB::table('financial_transactions')->where('kind', 'fee')->value('amount_cents'));

        $split = $this->tenant(fn () => PaymentSplit::query()->firstOrFail());
        $this->assertSame(['native', 'settled', 24801, 14881], [$split->mode, $split->status, $split->base_cents, $split->amount_cents]);

        // Credenciais criptografadas: a chave não aparece em texto no banco nem na auditoria.
        $this->assertStringNotContainsString('aact_teste_123', (string) DB::table('payment_gateways')->value('credentials'));
        $this->assertStringNotContainsString('aact_teste_123', DB::table('audit_logs')->get()->toJson());
    }

    public function test_asaas_requires_patient_cpf_and_marks_charge_failed(): void
    {
        $this->gateway('asaas', ['api_key' => 'k']);
        $r = $this->receivable(['cpf' => null]);
        Http::fake();

        $this->charge($r)->assertStatus(502)->assertJsonPath('code', 'cpf_required');
        $this->assertSame('failed', DB::table('payment_charges')->value('status'));
        Http::assertNothingSent();
    }

    public function test_amount_mismatch_and_duplicate_payment_go_to_review(): void
    {
        $this->gateway();
        $r = $this->receivable();
        $chargeId = $this->charge($r)->json('data.id');
        $charge = $this->tenant(fn () => PaymentCharge::query()->findOrFail($chargeId));

        $this->actingAs($this->clinic['admin'])->post(route('charges.simulate', $charge), ['outcome' => 'paid_wrong'])->assertSessionHas('error');
        $this->assertSame('review', $this->tenant(fn () => $charge->fresh())->status);
        $this->assertStringContainsString('diferente do cobrado', $this->tenant(fn () => $charge->fresh())->review_reason);
        $this->assertSame(0, DB::table('financial_transactions')->count());

        // Pago no balcão e depois online: não lança em duplicidade.
        $id = $this->as($this->clinic['admin'])->postJson('/api/v1/appointments', $this->bookPayload($this->patient('Outra', ['cpf' => '11144477735']), '08:30'))->json('data.id');
        $this->as($this->clinic['admin'])->postJson("/api/v1/appointments/{$id}/arrive");
        $r2 = $this->tenant(fn () => Receivable::query()->where('appointment_id', $id)->firstOrFail());
        $c2Id = $this->charge($r2)->json('data.id');
        $c2 = $this->tenant(fn () => PaymentCharge::query()->findOrFail($c2Id));
        $this->as($this->clinic['admin'])->postJson("/api/v1/receivables/{$r2->id}/receive", ['method' => 'pix', 'amount_cents' => 25000])->assertCreated();
        $this->actingAs($this->clinic['admin'])->post(route('charges.simulate', $c2), ['outcome' => 'paid']);
        $this->assertSame('review', $this->tenant(fn () => $c2->fresh())->status);
        $this->assertSame(1, DB::table('financial_transactions')->where('kind', 'receipt')->count());
    }

    public function test_gateway_refund_reverses_ledger_and_split(): void
    {
        $this->gateway();
        $this->rule(5000);
        $r = $this->receivable();
        $chargeId = $this->charge($r)->json('data.id');
        $charge = $this->tenant(fn () => PaymentCharge::query()->findOrFail($chargeId));
        $this->actingAs($this->clinic['admin'])->post(route('charges.simulate', $charge), ['outcome' => 'paid']);
        $this->assertSame('internal', DB::table('payment_splits')->value('mode')); // MOCK sem carteira → repasse interno

        $reception = $this->userWithRole($this->company(), 'recepcao', $this->branch());
        $this->actingAs($reception)->post(route('charges.refund', $charge), ['confirm' => 1])->assertForbidden();

        $this->actingAs($this->clinic['admin'])->post(route('charges.refund', $charge), ['confirm' => 1])->assertRedirect();
        $this->assertSame('refunded', $this->tenant(fn () => $charge->fresh())->status);
        $this->assertSame('open', $r->fresh()->status);
        $this->assertSame(1, DB::table('financial_transactions')->where('kind', 'reversal')->count());
        $this->assertSame('reversed', DB::table('payment_splits')->value('status'));
    }

    public function test_internal_split_settlement_and_clawback(): void
    {
        $this->rule(5000);
        $fin = $this->userWithRole($this->company(), 'financeiro');
        $r = $this->receivable();
        $txn = $this->as($fin)->postJson("/api/v1/receivables/{$r->id}/receive", ['method' => 'credit_card', 'amount_cents' => 25000])->json('data.transaction.id');
        $split = DB::table('payment_splits')->first();
        $this->assertSame(['internal', 'pending', 12500], [$split->mode, $split->status, (int) $split->amount_cents]);

        $this->actingAs($fin)->post(route('splits.settle', $this->doctor), ['from' => '2026-10-01', 'to' => '2026-10-31'])->assertRedirect();
        $payable = DB::table('payables')->first();
        $this->assertSame(12500, (int) $payable->amount_cents);
        $this->assertStringContainsString('Repasse médico', $payable->description);
        $this->assertSame('settled', DB::table('payment_splits')->value('status'));
        $this->post(route('splits.settle', $this->doctor), ['from' => '2026-10-01', 'to' => '2026-10-31'])->assertSessionHas('error');

        // Estorno depois do repasse → devolução descontada no próximo fechamento.
        $this->as($fin)->postJson("/api/v1/transactions/{$txn}/reverse", ['reason' => 'Paciente contestou a cobrança'])->assertCreated();
        $this->assertSame(-12500, (int) DB::table('payment_splits')->where('status', 'pending')->value('amount_cents'));

        $this->actingAs($fin)->get(route('splits.index', ['from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk()->assertSee('Dra. Agenda')->assertSee('-R$ 125,00');
    }

    public function test_periodic_sync_confirms_payment_when_webhook_was_lost(): void
    {
        $this->gateway();
        $r = $this->receivable();
        $providerId = $this->charge($r)->json('data.provider_charge_id');
        app(MockProvider::class)->simulate($providerId, 'paid'); // sem webhook

        $this->artisan('aivexa:payments:sync')->assertSuccessful();
        $this->assertSame('paid', DB::table('payment_charges')->value('status'));
        $this->assertSame('paid', $r->fresh()->status);
    }

    public function test_cielo_link_confirmed_by_notification_and_query(): void
    {
        $gw = $this->gateway('cielo', ['client_id' => 'cid', 'client_secret' => 'sec'], 'production');
        $r = $this->receivable();
        $paid = false;

        Http::fake([
            'cieloecommerce.cielo.com.br/api/public/v2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 1200]),
            'cieloecommerce.cielo.com.br/api/public/v1/products/' => Http::response(['id' => 'link-123', 'shortUrl' => 'https://bit.ly/x']),
            'cieloecommerce.cielo.com.br/api/public/v1/products/link-123/payments' => function () use (&$paid) {
                return Http::response(['productId' => 'link-123', 'orders' => $paid
                    ? [['orderNumber' => 'ord1', 'payment' => ['price' => 25000, 'createdDate' => '2026-10-05T10:00:00', 'status' => 'Paid']]]
                    : [['orderNumber' => 'ord0', 'payment' => ['price' => 25000, 'createdDate' => '2026-10-05T09:00:00', 'status' => 'Denied']]]]);
            },
        ]);

        $this->charge($r, 'credit_card')->assertCreated()->assertJsonPath('data.payment_url', 'https://bit.ly/x')->assertJsonPath('data.test_mode', null);
        Http::assertSent(fn (HttpRequest $req) => str_ends_with($req->url(), 'v1/products/') && $req['price'] === '25000' && $req->hasHeader('Authorization', 'Bearer tok'));

        $notify = fn (string $status) => $this->post(route('payments.webhook', $gw->id).'?token='.$gw->webhook_token,
            ['checkout_cielo_order_number' => 'ord'.$status, 'payment_status' => $status, 'product_id' => 'link-123', 'amount' => '25000']);

        $this->post(route('payments.webhook', $gw->id), ['product_id' => 'link-123', 'payment_status' => '2'])->assertUnauthorized();
        $notify('3')->assertOk();
        $this->assertSame('pending', DB::table('payment_charges')->value('status')); // negado: link continua aberto

        $paid = true;
        $notify('2')->assertOk();
        $this->assertSame('paid', DB::table('payment_charges')->value('status'));
        $this->assertSame('credit_card', DB::table('financial_transactions')->where('kind', 'receipt')->value('method'));
    }

    public function test_gateway_admin_screens_permissions_and_isolation(): void
    {
        $gw = $this->gateway('asaas', ['api_key' => 'segredo-asaas']);
        $fin = $this->userWithRole($this->company(), 'financeiro');

        $page = $this->actingAs($this->clinic['admin'])->get(route('gateways.index'))->assertOk()->assertSee($gw->webhookUrl())->assertSee('SANDBOX');
        $page->assertDontSee('segredo-asaas');
        $this->put(route('gateways.update', $gw), ['name' => 'ASAAS Matriz', 'mode' => 'sandbox', 'credentials' => ['api_key' => '']])->assertRedirect();
        $this->assertSame('segredo-asaas', $gw->fresh()->credential('api_key')); // campo vazio mantém a chave
        $this->actingAs($fin)->get(route('gateways.index'))->assertForbidden();
        $this->get(route('charges.index'))->assertOk();
        $this->get(route('splits.index'))->assertOk();

        // Gateway de outra clínica não alcança cobranças desta.
        $mock = $this->gateway();
        $r = $this->receivable();
        $providerId = $this->charge($r)->json('data.provider_charge_id');
        app(MockProvider::class)->simulate($providerId, 'paid');
        $other = $this->createClinic('Clínica Beta');
        $otherGw = $this->context()->runFor($other['company']->id, fn () => app(PaymentService::class)->saveGateway($other['admin'], ['provider' => 'mock']));
        $this->postJson(route('payments.webhook', $otherGw->id), ['id' => 'x1', 'charge_id' => $providerId], ['X-Mock-Token' => $otherGw->webhook_token])->assertJsonPath('message', 'ignorado');
        $this->assertSame('pending', DB::table('payment_charges')->where('provider_charge_id', $providerId)->value('status'));

        $this->actingAs($this->clinic['admin'])->get(route('receivables.show', $r))->assertOk()->assertSee('Gerar link de pagamento')->assertSee('MOCK: paciente pagou');
    }
}
