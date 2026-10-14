<?php

namespace Tests\Feature\Sprint;

use App\Modules\Identity\Models\User;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentGateway;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Sprint\Models\SprintPayment;
use App\Modules\Sprint\Models\SprintProfessional;
use App\Modules\Sprint\Models\SprintSale;
use App\Modules\Sprint\Models\SprintSetting;
use App\Modules\Sprint\Models\SprintSplit;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Scheduling\SchedulingSetup;
use Tests\TestCase;

/** Melhor Sprint: vendas com divisão, pagamentos mistos, split nativo ASAAS/Cielo, comissão, repasse e NFS-e. */
class SprintTest extends TestCase
{
    use SchedulingSetup;

    private const DRA_SUBORDINATE = '7c5c2b5e-2f4a-4b8e-9d1a-0f3e6a9b1c2d';

    private const PLATFORM_SUBORDINATE = '0b6f1d1e-9a77-4c1e-8f5e-3c2d1a0b9e8f';

    private SprintProfessional $dra;

    private SprintProfessional $lab;

    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScheduling();
        config(['sprint.commission_bp' => 150, 'sprint.platform.asaas_wallet_id' => null, 'sprint.platform.cielo_subordinate_id' => null]);

        [$this->dra, $this->lab] = $this->tenant(fn () => [
            SprintProfessional::create(['kind' => 'doctor', 'doctor_id' => $this->doctor->id, 'name' => 'Dra. Agenda', 'split_type' => 'percent', 'split_value' => 6000,
                'cielo_subordinate_id' => self::DRA_SUBORDINATE, 'asaas_wallet_id' => 'wallet-dra', 'pix_key' => 'dra@pix.test']),
            SprintProfessional::create(['kind' => 'partner', 'name' => 'Laboratório Parceiro', 'split_type' => 'fixed', 'split_value' => 3000]),
        ]);
    }

    private function tenant(\Closure $fn): mixed
    {
        return $this->context()->runFor($this->company()->id, $fn);
    }

    private function as(User $user): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->tokens[$user->id] ??= $this->apiToken($user);
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->tokens[$user->id]);
    }

    private function gateway(string $provider, array $credentials = [], string $mode = 'sandbox'): PaymentGateway
    {
        return $this->tenant(fn () => app(PaymentService::class)->saveGateway($this->clinic['admin'],
            ['provider' => $provider, 'mode' => $provider === 'mock' ? 'mock' : $mode, 'credentials' => $credentials, 'is_default' => true]));
    }

    /** Venda pela tela: consulta R$ 250 (Dra 60%), exame R$ 100 (laboratório R$ 30 fixo) e taxa R$ 20 só da clínica. */
    private function webSale(array $extra = []): SprintSale
    {
        $this->actingAs($this->clinic['admin'])->post(route('sprint.sales.store'), $extra + ['items' => [
            ['professional_id' => $this->dra->id, 'description' => 'Consulta', 'amount' => '250,00'],
            ['professional_id' => $this->lab->id, 'description' => 'Exame', 'amount' => '100,00'],
            ['description' => 'Taxa de sala', 'amount' => '20,00'],
        ]])->assertRedirect();

        return $this->tenant(fn () => SprintSale::query()->latest('number')->firstOrFail());
    }

    private function splits(SprintSale $sale): array
    {
        $rows = $this->tenant(fn () => SprintSplit::query()->where('sale_id', $sale->id)->get()
            ->map(fn ($s) => [$s->party, $s->professional_id === $this->dra->id ? 'dra' : ($s->professional_id ? 'lab' : null), $s->amount_cents, $s->mode, $s->status])->all());
        usort($rows, fn ($a, $b) => [$a[0], (string) $a[1], $a[3], $a[2]] <=> [$b[0], (string) $b[1], $b[3], $b[2]]);

        return $rows;
    }

    public function test_mixed_cash_and_terminal_payments_split_every_cent_between_professionals_clinic_and_platform(): void
    {
        $sale = $this->webSale();
        $this->assertSame([37000, 18000, 19000, 150, 'MS-000001'], [$sale->total_cents, $sale->professional_cents, $sale->house_cents, $sale->commission_bp, $sale->code()]);
        $this->assertSame('sprint', DB::table('receivables')->where('id', $sale->receivable_id)->value('origin'));

        $page = $this->actingAs($this->clinic['admin'])->get(route('sprint.sales.show', $sale))->assertOk()
            ->assertSee('Melhor Sprint')->assertSee('melhor-sprint-mark.webp')->assertSee('Receber pagamento')->assertSee('R$ 370,00');

        // Dinheiro exige caixa aberto.
        $pay = fn (array $data) => $this->actingAs($this->clinic['admin'])->post(route('sprint.sales.pay', $sale), $data + ['idempotency_key' => str_repeat('k', 10).uniqid('', true)]);
        $pay(['method' => 'cash', 'channel' => 'cash', 'amount' => '100,00'])->assertSessionHas('error', 'Abra o seu caixa para movimentar dinheiro.');
        $this->post(route('cash.open'), ['branch_id' => $this->clinic['branch']->id, 'opening' => '50,00'])->assertRedirect();

        $pay(['method' => 'cash', 'channel' => 'cash', 'amount' => '100,00', 'cash_given' => '150,00'])->assertSessionHas('success', 'Pagamento confirmado. Troco: R$ 50,00.');
        $this->assertSame('partial', $sale->fresh()->status);

        // Débito na maquininha exige NSU; crédito na Cielo com split na maquininha quita a venda.
        $pay(['method' => 'debit_card', 'channel' => 'cielo_terminal', 'amount' => '10,00'])->assertSessionHas('error');
        $pay(['method' => 'debit_card', 'channel' => 'asaas', 'amount' => '10,00'])->assertSessionHas('error');
        $pay(['method' => 'credit_card', 'channel' => 'cielo_terminal', 'amount' => '270,01', 'nsu' => '123'])->assertSessionHas('error');
        $pay(['method' => 'credit_card', 'channel' => 'cielo_terminal', 'amount' => '270,00', 'nsu' => '987654', 'card_brand' => 'Visa', 'installments' => 2, 'terminal_split' => 1])
            ->assertSessionHas('success', 'Pagamento confirmado.');

        $sale = $sale->fresh();
        $this->assertSame('paid', $sale->status);
        $this->assertSame('paid', DB::table('receivables')->where('id', $sale->receivable_id)->value('status'));

        // 1º pagamento (R$ 100): proporcional. 2º (R$ 270): fecha os centavos de cada profissional.
        $this->assertSame([
            ['house', null, 4985, 'internal', 'settled'],
            ['house', null, 13460, 'internal', 'settled'],
            ['platform', null, 150, 'internal', 'to_invoice'],
            ['platform', null, 405, 'internal', 'to_invoice'],
            ['professional', 'dra', 4054, 'internal', 'pending'],
            ['professional', 'dra', 10946, 'native', 'settled'],
            ['professional', 'lab', 811, 'internal', 'pending'],
            ['professional', 'lab', 2189, 'internal', 'pending'],
        ], $this->splits($sale));
        $this->assertSame(37000, (int) DB::table('sprint_splits')->sum('amount_cents'));
        $this->assertSame(['cielo_terminal'], DB::table('sprint_splits')->where('mode', 'native')->pluck('source')->all());

        // Comprovante e tela pronta para NFS-e/estorno.
        $this->get(route('sprint.sales.receipt', $sale))->assertOk()->assertSee('MS-000001')->assertSee('Laboratório Parceiro')->assertDontSee('Divisão dos valores');
        $this->get(route('sprint.sales.receipt', [$sale, 'via' => 'clinica']))->assertOk()->assertSee('Divisão dos valores')->assertSee('R$ 190,00');
        $this->get(route('sprint.index'))->assertOk()->assertSee('MS-000001')->assertSee('R$ 370,00');
        $pay(['method' => 'cash', 'channel' => 'cash', 'amount' => '1,00'])->assertSessionHas('error');
    }

    public function test_asaas_pix_sends_native_split_for_professional_and_platform_and_confirms_on_screen(): void
    {
        config(['sprint.platform.asaas_wallet_id' => 'wallet-plataforma']);
        $gw = $this->gateway('asaas', ['api_key' => '$aact_sprint']);
        $patient = $this->patient('Cliente Pix', ['cpf' => '52998224725']);
        $status = 'PENDING';

        Http::fake([
            'api-sandbox.asaas.com/v3/customers' => Http::response(['id' => 'cus_ms']),
            'api-sandbox.asaas.com/v3/payments' => Http::response(['id' => 'pay_ms', 'invoiceUrl' => 'https://sandbox.asaas.com/i/pay_ms', 'status' => 'PENDING']),
            'api-sandbox.asaas.com/v3/payments/pay_ms/pixQrCode' => Http::response(['encodedImage' => base64_encode('png'), 'payload' => '00020126PIXSPRINT']),
            'api-sandbox.asaas.com/v3/payments/pay_ms' => function () use (&$status) {
                return Http::response(['id' => 'pay_ms', 'status' => $status, 'value' => 350.0, 'netValue' => 348.25, 'billingType' => 'PIX', 'paymentDate' => '2026-10-05']);
            },
            'api-sandbox.asaas.com/v3/invoices' => Http::response(['id' => 'inv_ms', 'status' => 'SCHEDULED']),
            'api-sandbox.asaas.com/v3/invoices/inv_ms/authorize' => Http::response(['id' => 'inv_ms', 'status' => 'AUTHORIZED', 'number' => '1234', 'validationCode' => 'ABC-1', 'pdfUrl' => 'https://sandbox.asaas.com/nf/inv_ms.pdf']),
        ]);

        // API: venda + pagamento PIX com idempotência.
        $saleId = $this->as($this->clinic['admin'])->postJson('/api/v1/sprint/sales', ['patient_id' => $patient->id, 'items' => [
            ['professional_id' => $this->dra->id, 'description' => 'Consulta', 'amount_cents' => 25000],
            ['professional_id' => $this->lab->id, 'description' => 'Exame', 'amount_cents' => 10000],
        ]])->assertCreated()->assertJsonPath('data.house_cents', 17000)->json('data.id');

        $key = ['Idempotency-Key' => 'pix-sprint-'.str_repeat('z', 10)];
        $paid = $this->as($this->clinic['admin'])->postJson("/api/v1/sprint/sales/{$saleId}/payments", ['method' => 'pix', 'channel' => 'asaas', 'amount_cents' => 35000], $key)
            ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.charge.pix_payload', '00020126PIXSPRINT')->assertJsonPath('data.charge.test_mode', 'SANDBOX');
        $this->as($this->clinic['admin'])->postJson("/api/v1/sprint/sales/{$saleId}/payments", ['method' => 'pix', 'channel' => 'asaas', 'amount_cents' => 35000], $key)
            ->assertOk()->assertJsonPath('data.id', $paid->json('data.id'));
        $this->as($this->clinic['admin'])->postJson("/api/v1/sprint/sales/{$saleId}/payments", ['method' => 'cash', 'channel' => 'cash', 'amount_cents' => 100], ['Idempotency-Key' => str_repeat('c', 20)])
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_amount'); // o PIX em aberto reserva o saldo

        Http::assertSent(fn (HttpRequest $req) => $req->url() === 'https://api-sandbox.asaas.com/v3/payments' && $req->method() === 'POST'
            && $req['value'] == 350 && $req['split'] === [['walletId' => 'wallet-dra', 'fixedValue' => 150.0], ['walletId' => 'wallet-plataforma', 'fixedValue' => 5.25]]);

        $sale = $this->tenant(fn () => SprintSale::query()->findOrFail($saleId));
        $this->actingAs($this->clinic['admin'])->get(route('sprint.sales.show', $sale))->assertOk()->assertSee('Aguardando o pagamento')->assertSee('00020126PIXSPRINT');
        $this->getJson(route('sprint.sales.status', $sale))->assertOk()->assertJsonPath('sale.status', 'open')->assertJsonPath('payments.0.status', 'pending');

        // Webhook autenticado + consulta confirmam; a tela recebe "pago".
        $status = 'RECEIVED';
        $this->postJson(route('payments.webhook', $gw->id), ['id' => 'evt_ms', 'event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_ms', 'status' => 'RECEIVED']], ['asaas-access-token' => $gw->webhook_token])->assertOk();
        $this->getJson(route('sprint.sales.status', $sale))->assertOk()->assertJsonPath('sale.status', 'paid')->assertJsonPath('payments.0.status', 'paid')->assertJsonPath('sale.balance_cents', 0);

        $this->assertSame([
            ['fee', null, 175, 'native', 'settled'],
            ['house', null, 16300, 'internal', 'settled'],
            ['platform', null, 525, 'native', 'settled'],
            ['professional', 'dra', 15000, 'native', 'settled'],
            ['professional', 'lab', 3000, 'internal', 'pending'],
        ], $this->splits($sale));
        $this->assertSame(0, DB::table('payment_splits')->count()); // o split médico tradicional não duplica a divisão

        // NFS-e sobre a parte da clínica (R$ 170), não sobre o total (R$ 350).
        $this->tenant(fn () => SprintSetting::create(['nfse_enabled' => true, 'nfse_gateway_id' => $gw->id, 'nfse_service_code' => '04.03', 'nfse_iss_bp' => 200]));
        $this->actingAs($this->clinic['admin'])->post(route('sprint.sales.nfse', $sale))->assertSessionHas('success');
        Http::assertSent(fn (HttpRequest $req) => $req->url() === 'https://api-sandbox.asaas.com/v3/invoices' && $req['value'] == 170 && $req['customer'] === 'cus_ms'
            && $req['municipalServiceCode'] === '04.03' && $req['taxes']['iss'] == 2 && str_contains($req['serviceDescription'], 'somente à parte da clínica: R$ 170,00'));
        $this->assertSame(['authorized', '1234', 17000], array_values((array) DB::table('sprint_invoices')->first(['status', 'number', 'value_cents'])));
        $this->actingAs($this->clinic['admin'])->post(route('sprint.sales.nfse', $sale))->assertSessionHas('error', 'Esta venda já tem NFS-e emitida ou em processamento.');
    }

    public function test_cielo_online_card_splits_to_professional_and_platform_subordinates(): void
    {
        config(['sprint.platform.cielo_subordinate_id' => self::PLATFORM_SUBORDINATE]);
        $this->gateway('cielo_api', ['merchant_id' => 'mid', 'merchant_key' => 'mkey', 'client_id' => 'cid', 'client_secret' => 'csec']);
        Http::fake([
            'authsandbox.braspag.com.br/oauth2/token' => Http::response(['access_token' => 'oauth', 'expires_in' => 1200]),
            'apisandbox.cieloecommerce.cielo.com.br/1/sales/' => Http::response(['Payment' => ['PaymentId' => 'pid-ms', 'Status' => 2, 'ReturnMessage' => 'Operation Successful']], 201),
            'apiquerysandbox.cieloecommerce.cielo.com.br/1/sales/*' => Http::response(['Payment' => ['PaymentId' => 'pid-ms', 'Status' => 2, 'Amount' => 20000, 'CapturedAmount' => 20000, 'CapturedDate' => '2026-10-05 08:40:00']]),
        ]);

        $this->actingAs($this->clinic['admin'])->post(route('sprint.sales.store'), ['items' => [
            ['professional_id' => $this->dra->id, 'description' => 'Procedimento', 'amount' => '200,00', 'split_type' => 'percent', 'split_value' => '50,00'],
        ]])->assertRedirect();
        $sale = $this->tenant(fn () => SprintSale::query()->firstOrFail());
        $this->assertSame(10000, $sale->professional_cents); // regra ajustada na venda (50% em vez de 60%)

        $this->post(route('sprint.sales.pay', $sale), ['method' => 'credit_card', 'channel' => 'cielo_api', 'amount' => '200,00', 'idempotency_key' => str_repeat('w', 20)])->assertSessionHas('success');
        $charge = $this->tenant(fn () => PaymentCharge::query()->firstOrFail());
        $this->get(route('sprint.sales.show', $sale))->assertOk()->assertSee('Abrir página do cartão')->assertSee($charge->publicUrl());

        $this->post(route('payments.card_pay', $charge->public_token), ['payment_token' => '6b2e1d5c-3a4f-4e8b-9c7d-1f2a3b4c5d6e', 'brand' => 'Visa', 'installments' => 1, 'holder_name' => 'CLIENTE'])->assertRedirect();

        Http::assertSent(fn (HttpRequest $req) => $req->url() === 'https://apisandbox.cieloecommerce.cielo.com.br/1/sales/'
            && $req['Payment']['SplitPayments'] === [['SubordinateMerchantId' => self::DRA_SUBORDINATE, 'Amount' => 10000], ['SubordinateMerchantId' => self::PLATFORM_SUBORDINATE, 'Amount' => 300]]);
        $this->assertSame('paid', $sale->fresh()->status);
        $this->assertSame([
            ['house', null, 9700, 'internal', 'settled'],
            ['platform', null, 300, 'native', 'settled'],
            ['professional', 'dra', 10000, 'native', 'settled'],
        ], $this->splits($sale));
    }

    public function test_settlement_refund_clawback_and_automatic_mock_nfse(): void
    {
        $mock = $this->gateway('mock');
        $this->tenant(fn () => SprintSetting::create(['nfse_enabled' => true, 'nfse_auto' => true, 'nfse_gateway_id' => $mock->id]));
        $fin = $this->userWithRole($this->company(), 'financeiro');

        // Venda paga por PIX MOCK (mesmo fluxo do webhook) → NFS-e automática sobre a parte da clínica.
        $sale = $this->webSale(['issue_nfse' => 1, 'patient_id' => $this->patient('Cliente NF')->id]);
        $this->post(route('sprint.sales.pay', $sale), ['method' => 'pix', 'channel' => 'mock', 'amount' => '370,00', 'idempotency_key' => str_repeat('m', 20)])->assertSessionHas('success');
        $payment = $this->tenant(fn () => SprintPayment::query()->firstOrFail());
        $this->get(route('sprint.sales.show', $sale))->assertSee('MOCK: cliente pagou');
        $this->post(route('sprint.payments.simulate', $payment))->assertSessionHas('confirmed', $payment->id);

        $this->assertSame('paid', $sale->fresh()->status);
        $invoice = DB::table('sprint_invoices')->first();
        $this->assertSame(['mock', 'authorized', 19000, 37000], [$invoice->provider, $invoice->status, (int) $invoice->value_cents, (int) $invoice->sale_total_cents]);

        // Fechamento do repasse do laboratório (interno) → conta a pagar.
        $this->actingAs($fin)->get(route('sprint.reports.index', ['from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk()
            ->assertSee('Laboratório Parceiro')->assertSee('R$ 30,00')->assertSee('R$ 190,00');
        $this->actingAs($fin)->post(route('sprint.reports.settle', $this->lab), ['from' => '2026-10-01', 'to' => '2026-10-31'])->assertRedirect();
        $payable = DB::table('payables')->first();
        $this->assertSame([3000, 'Laboratório Parceiro'], [(int) $payable->amount_cents, $payable->supplier]);
        $this->assertSame('Repasse a parceiros', DB::table('financial_categories')->where('id', $payable->category_id)->value('name'));
        $this->actingAs($fin)->post(route('sprint.reports.settle', $this->lab), ['from' => '2026-10-01', 'to' => '2026-10-31'])->assertSessionHas('error');

        $csv = $this->actingAs($fin)->get(route('sprint.reports.export', ['from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk()->streamedContent();
        $this->assertStringContainsString('MS-000001;"Cliente NF', $csv);

        // Estorno: o gateway devolve; a parte já repassada vira devolução; a parte nativa é desfeita.
        $this->actingAs($fin)->post(route('sprint.sales.refund', $sale), ['reason' => 'Cliente desistiu do exame'])->assertSessionHas('success');
        $this->actingAs($this->clinic['admin'])->post(route('charges.simulate', DB::table('payment_charges')->value('id')), ['outcome' => 'refunded']);

        $this->assertSame('refunded', $sale->fresh()->status);
        $this->assertSame('refunded', DB::table('sprint_payments')->value('status'));
        $this->assertSame(-3000, (int) DB::table('sprint_splits')->where('professional_id', $this->lab->id)->where('status', 'pending')->sum('amount_cents'));
        $this->assertSame(0, DB::table('sprint_splits')->whereIn('party', ['house', 'platform'])->where('status', '!=', 'reversed')->count());
        $this->actingAs($this->clinic['admin'])->get(route('sprint.sales.show', $sale))->assertSee('Venda estornada com NFS-e ativa');

        $this->post(route('sprint.nfse.cancel', DB::table('sprint_invoices')->value('id')))->assertSessionHas('success');
        $this->assertSame('cancelled', DB::table('sprint_invoices')->value('status'));
    }

    public function test_permissions_isolation_and_commission_controlled_only_by_the_platform(): void
    {
        $reception = $this->userWithRole($this->company(), 'recepcao');
        $sale = $this->webSale();

        $this->actingAs($reception)->get(route('sprint.sales.create'))->assertOk()->assertSee('Consulta', false);
        $this->actingAs($reception)->get(route('sprint.sales.show', $sale))->assertOk();
        $this->actingAs($reception)->get(route('sprint.settings'))->assertForbidden();
        $this->actingAs($reception)->get(route('sprint.professionals.index'))->assertForbidden();
        $this->actingAs($reception)->get(route('sprint.reports.index'))->assertForbidden();
        $this->actingAs($reception)->post(route('sprint.sales.cancel', $sale), ['reason' => 'teste'])->assertForbidden();

        // Outra clínica não enxerga a venda.
        $other = $this->createClinic('Clínica Beta');
        $this->actingAs($other['admin'])->get(route('sprint.sales.show', $sale->id))->assertNotFound();
        $this->as($other['admin'])->getJson("/api/v1/sprint/sales/{$sale->id}")->assertNotFound();

        // A clínica não altera a própria comissão (nem por settings livres da API).
        $this->as($this->clinic['admin'])->patchJson('/api/v1/company', ['sprint_commission_bp' => 0, 'settings' => ['sprint_commission_bp' => 0]])->assertOk();
        $this->assertNull(DB::table('companies')->where('id', $this->company()->id)->value('sprint_commission_bp'));

        // Super Admin define a taxa da clínica e marca a comissão a cobrar como cobrada.
        $super = $this->context()->runAsSystem(function () {
            $u = new User(['name' => 'Root', 'email' => 'root.sprint@example.test', 'password' => self::PASSWORD]);
            $u->is_super_admin = true;
            $u->save();

            return $u;
        });
        $this->actingAs($super)->put(route('platform.sprint.rate', $this->company()), ['rate' => '2,50'])->assertSessionHas('success');
        $this->assertSame(250, (int) DB::table('companies')->where('id', $this->company()->id)->value('sprint_commission_bp'));
        $this->actingAs($this->clinic['admin'])->get(route('sprint.settings'))->assertOk()->assertSee('2,50%');

        $this->actingAs($this->clinic['admin'])->post(route('cash.open'), ['branch_id' => $this->clinic['branch']->id, 'opening' => '0,00']);
        $this->post(route('sprint.sales.pay', $sale), ['method' => 'cash', 'channel' => 'cash', 'amount' => '370,00', 'idempotency_key' => str_repeat('p', 20)])->assertSessionHas('success');
        $this->assertSame(555, (int) DB::table('sprint_splits')->where('party', 'platform')->value('amount_cents')); // taxa da venda (1,5%) congelada na criação

        $this->actingAs($super)->get(route('platform.sprint.index', ['from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk()->assertSee('Clínica Alfa')->assertSee('R$ 5,55');
        $this->actingAs($super)->post(route('platform.sprint.invoice', $this->company()), ['from' => '2026-10-01', 'to' => '2026-10-31'])->assertSessionHas('success');
        $this->assertSame('invoiced', DB::table('sprint_splits')->where('party', 'platform')->value('status'));
        $this->actingAs($this->clinic['admin'])->get(route('platform.sprint.index'))->assertForbidden();
    }

    public function test_professional_registry_and_doctor_import(): void
    {
        $this->actingAs($this->clinic['admin'])->post(route('sprint.professionals.store'), [
            'kind' => 'partner', 'name' => 'Fisio Parceira', 'document' => '529.982.247-25', 'split_type' => 'percent', 'split_value' => '70,00',
            'asaas_wallet_id' => 'wallet-fisio', 'cielo_subordinate_id' => 'nao-e-uuid',
        ])->assertSessionHasErrors('cielo_subordinate_id');
        $this->post(route('sprint.professionals.store'), [
            'kind' => 'partner', 'name' => 'Fisio Parceira', 'document' => '529.982.247-25', 'split_type' => 'percent', 'split_value' => '70,00', 'asaas_wallet_id' => 'wallet-fisio',
        ])->assertRedirect(route('sprint.professionals.index'));
        $fisio = $this->tenant(fn () => SprintProfessional::query()->where('name', 'Fisio Parceira')->firstOrFail());
        $this->assertSame(['52998224725', 7000, 'wallet-fisio'], [$fisio->document, $fisio->split_value, $fisio->asaas_wallet_id]);

        $this->post(route('sprint.professionals.store'), ['kind' => 'partner', 'name' => 'Errado', 'split_type' => 'percent', 'split_value' => '120,00'])->assertSessionHasErrors('split_value');
        $this->put(route('sprint.professionals.update', $fisio), ['kind' => 'partner', 'name' => 'Fisio Parceira', 'split_type' => 'fixed', 'split_value' => '45,00', 'asaas_wallet_id' => ''])->assertRedirect();
        $this->assertSame(['fixed', 4500, null], [$fisio->fresh()->split_type, $fisio->fresh()->split_value, $fisio->fresh()->asaas_wallet_id]);

        $this->get(route('sprint.professionals.index'))->assertOk()->assertSee('Fisio Parceira')->assertSee('R$ 45,00');
        $this->post(route('sprint.professionals.import'))->assertSessionHas('success', 'Todos os médicos ativos já estão cadastrados.'); // a Dra. já está vinculada
        $this->patch(route('sprint.professionals.toggle', $fisio))->assertRedirect();
        $this->assertFalse($fisio->fresh()->is_active);

        // Profissional inativo não entra em venda nova.
        $this->post(route('sprint.sales.store'), ['items' => [['professional_id' => $fisio->id, 'description' => 'Sessão', 'amount' => '100,00']]])->assertSessionHas('error');
    }
}
