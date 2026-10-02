<?php

namespace Tests\Feature\Payments;

use App\Modules\Finance\Models\Receivable;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentGateway;
use App\Modules\Payments\Models\SplitRule;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Scheduling\SchedulingSetup;
use Tests\TestCase;

/** Split da Cielo: API E-commerce (cartão tokenizado + SplitPayments) e maquininha com split. */
class CieloSplitTest extends TestCase
{
    use SchedulingSetup;

    private const SUBORDINATE = '7c5c2b5e-2f4a-4b8e-9d1a-0f3e6a9b1c2d';

    private const PAYMENT_ID = '24bc8366-fc31-4d6c-8555-17049a836a07';

    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScheduling();
        $this->context()->runFor($this->company()->id, function () {
            SplitRule::create(['doctor_id' => $this->doctor->id, 'type' => 'percent', 'value' => 6000]);
            $this->doctor->forceFill(['cielo_subordinate_id' => self::SUBORDINATE])->save();
        });
    }

    private function as($user): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->tokens[$user->id] ??= $this->apiToken($user);
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->tokens[$user->id]);
    }

    private function receivable(): Receivable
    {
        $id = $this->as($this->clinic['admin'])->postJson('/api/v1/appointments', $this->bookPayload($this->patient('Paciente Cartão'), '08:00'))->json('data.id');
        $this->as($this->clinic['admin'])->postJson("/api/v1/appointments/{$id}/arrive")->assertCreated();

        return $this->context()->runFor($this->company()->id, fn () => Receivable::query()->where('appointment_id', $id)->firstOrFail());
    }

    private function gateway(): PaymentGateway
    {
        return $this->context()->runFor($this->company()->id, fn () => app(PaymentService::class)->saveGateway($this->clinic['admin'], [
            'provider' => 'cielo_api', 'mode' => 'sandbox', 'is_default' => true,
            'credentials' => ['merchant_id' => 'mid-123', 'merchant_key' => 'mkey-secreta', 'client_id' => 'cid', 'client_secret' => 'csec'],
            'settings' => ['max_installments' => 3, 'soft_descriptor' => 'CLINICADEMO'],
        ]));
    }

    private function charge(Receivable $r, string $type = 'credit_card'): TestResponse
    {
        return $this->as($this->clinic['admin'])->postJson("/api/v1/receivables/{$r->id}/charges",
            ['billing_type' => $type, 'amount_cents' => 25000, 'due_date' => '2026-10-08'], ['Idempotency-Key' => 'cielo-'.str_repeat('x', 12).$type]);
    }

    private function fakeCielo(int $saleStatus = 2, int &$queryStatus = 2): void
    {
        Http::fake([
            'authsandbox.braspag.com.br/oauth2/token' => Http::response(['access_token' => 'oauth-tok', 'expires_in' => 1200]),
            'transactionsandbox.pagador.com.br/post/api/public/v2/accesstoken' => Http::response(['AccessToken' => 'sop-access-token', 'ExpiresIn' => '2026-10-05T10:00:00']),
            'apisandbox.cieloecommerce.cielo.com.br/1/sales/' => Http::response(['Payment' => ['PaymentId' => self::PAYMENT_ID, 'Status' => $saleStatus,
                'ReturnCode' => $saleStatus === 2 ? '6' : '57', 'ReturnMessage' => $saleStatus === 2 ? 'Operation Successful' : 'Card Expired']], 201),
            'apiquerysandbox.cieloecommerce.cielo.com.br/1/sales/*' => function () use (&$queryStatus) {
                return Http::response(['Payment' => ['PaymentId' => self::PAYMENT_ID, 'Status' => $queryStatus, 'Amount' => 25000, 'CapturedAmount' => 25000, 'CapturedDate' => '2026-10-05 08:40:00']]);
            },
            'apisandbox.cieloecommerce.cielo.com.br/1/sales/*/void' => Http::response(['Status' => 10]),
        ]);
    }

    public function test_online_card_payment_uses_tokenized_card_and_splits_to_the_doctor(): void
    {
        $this->gateway();
        $r = $this->receivable();
        $q = 2;
        $this->fakeCielo(2, $q);

        $this->charge($r, 'pix')->assertUnprocessable()->assertJsonPath('code', 'invalid_billing_type');
        $created = $this->charge($r)->assertCreated()->assertJsonPath('data.provider_charge_id', null)->assertJsonPath('data.test_mode', 'SANDBOX');
        $charge = $this->context()->runFor($this->company()->id, fn () => PaymentCharge::query()->findOrFail($created->json('data.id')));
        $this->assertEquals(['doctor_id' => $this->doctor->id, 'subordinate_id' => self::SUBORDINATE, 'expected_cents' => 15000],
            array_intersect_key($charge->split_snapshot, array_flip(['doctor_id', 'subordinate_id', 'expected_cents'])));

        // Página pública: formulário de cartão; CSP libera só o script de tokenização da Cielo nesta rota.
        $page = $this->get(route('payments.public', $charge->public_token))->assertOk()->assertSee('Pagar com cartão de crédito')->assertSee('bp-sop-cardnumber');
        $this->assertStringContainsString('transactionsandbox.pagador.com.br', $page->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('pagador.com.br', $this->get('/login')->headers->get('Content-Security-Policy'));
        $this->assertDoesNotMatchRegularExpression('/name="(card_?number|cvv|cardnumber)"/i', $page->getContent()); // campos do cartão sem "name"

        $this->getJson(route('payments.card_session', $charge->public_token))->assertOk()
            ->assertJsonPath('access_token', 'sop-access-token')->assertJsonPath('environment', 'sandbox')
            ->assertJsonPath('script_url', 'https://transactionsandbox.pagador.com.br/post/Scripts/silentorderpost-1.0.min.js');

        $this->post(route('payments.card_pay', $charge->public_token), ['payment_token' => '6b2e1d5c-3a4f-4e8b-9c7d-1f2a3b4c5d6e', 'brand' => 'Visa', 'installments' => 2, 'holder_name' => 'MARIA S SOUZA'])
            ->assertRedirect(route('payments.public', [$charge->public_token, 'r' => 'ok']));

        Http::assertSent(function (HttpRequest $req) {
            if ($req->url() !== 'https://apisandbox.cieloecommerce.cielo.com.br/1/sales/') {
                return false;
            }
            $p = $req['Payment'];

            return $p['Type'] === 'SplittedCreditCard' && $p['Amount'] === 25000 && $p['Installments'] === 2 && $p['Capture'] === true
                && $p['SplitPayments'] === [['SubordinateMerchantId' => self::SUBORDINATE, 'Amount' => 15000]]
                && $p['CreditCard'] === ['PaymentToken' => '6b2e1d5c-3a4f-4e8b-9c7d-1f2a3b4c5d6e', 'Brand' => 'Visa', 'Holder' => 'MARIA S SOUZA']
                && ! str_contains(json_encode($req->data()), 'CardNumber') && $req->hasHeader('MerchantKey', 'mkey-secreta');
        });

        $this->assertSame('paid', DB::table('payment_charges')->value('status'));
        $this->assertSame('paid', $r->fresh()->status);
        $split = DB::table('payment_splits')->first();
        $this->assertSame(['native', 'settled', 'cielo_api', 25000, 15000], [$split->mode, $split->status, $split->source, (int) $split->base_cents, (int) $split->amount_cents]);

        // Segundo envio (duplo clique / reenvio) não cobra de novo.
        $this->post(route('payments.card_pay', $charge->public_token), ['payment_token' => '6b2e1d5c-3a4f-4e8b-9c7d-1f2a3b4c5d6e', 'brand' => 'Visa', 'installments' => 1, 'holder_name' => 'X'])
            ->assertRedirect(route('payments.public', [$charge->public_token, 'r' => 'busy']));
        Http::assertSentCount(4); // oauth, accesstoken, venda e consulta — o reenvio não gerou nova venda
    }

    public function test_denied_card_keeps_charge_open_and_invalid_post_is_rejected(): void
    {
        $this->gateway();
        $r = $this->receivable();
        $q = 3;
        $this->fakeCielo(3, $q);
        $chargeId = $this->charge($r)->json('data.id');
        $charge = $this->context()->runFor($this->company()->id, fn () => PaymentCharge::query()->findOrFail($chargeId));

        $this->post(route('payments.card_pay', $charge->public_token), ['payment_token' => '6b2e1d5c-3a4f-4e8b-9c7d-1f2a3b4c5d6e', 'brand' => 'Visa', 'installments' => 1, 'holder_name' => 'MARIA'])
            ->assertRedirect(route('payments.public', [$charge->public_token, 'r' => 'denied']));
        $this->assertSame(['pending', null], [DB::table('payment_charges')->value('status'), DB::table('payment_charges')->value('provider_charge_id')]);
        $this->assertSame(0, DB::table('financial_transactions')->count());
        $this->get(route('payments.public', [$charge->public_token, 'r' => 'denied']))->assertSee('Cartão não aprovado');

        $this->post(route('payments.card_pay', $charge->public_token), ['payment_token' => 'nao-e-token', 'brand' => 'Visa', 'installments' => 1, 'holder_name' => 'X'])
            ->assertRedirect(route('payments.public', [$charge->public_token, 'r' => 'error']));
    }

    public function test_notification_and_refund_reverse_the_sale_and_split(): void
    {
        $gw = $this->gateway();
        $r = $this->receivable();
        $q = 2;
        $this->fakeCielo(2, $q);
        $chargeId = $this->charge($r)->json('data.id');
        $charge = $this->context()->runFor($this->company()->id, fn () => PaymentCharge::query()->findOrFail($chargeId));
        $this->post(route('payments.card_pay', $charge->public_token), ['payment_token' => '6b2e1d5c-3a4f-4e8b-9c7d-1f2a3b4c5d6e', 'brand' => 'Master', 'installments' => 1, 'holder_name' => 'MARIA']);

        $this->post(route('payments.webhook', $gw->id), ['PaymentId' => self::PAYMENT_ID, 'ChangeType' => 1])->assertUnauthorized();

        $this->actingAs($this->clinic['admin'])->post(route('charges.refund', $charge), ['confirm' => 1])->assertRedirect();
        Http::assertSent(fn (HttpRequest $req) => $req->method() === 'PUT' && str_ends_with($req->url(), '/1/sales/'.self::PAYMENT_ID.'/void'));

        // A Cielo confirma o cancelamento depois (notificação) → baixa reversa e split desfeito.
        $q = 10;
        $this->postJson(route('payments.webhook', $gw->id).'?token='.$gw->webhook_token, ['PaymentId' => self::PAYMENT_ID, 'ChangeType' => 1])->assertOk()->assertJsonPath('message', 'ok');
        $this->assertSame('refunded', DB::table('payment_charges')->value('status'));
        $this->assertSame('open', $r->fresh()->status);
        $this->assertSame('reversed', DB::table('payment_splits')->value('status'));
    }

    public function test_terminal_sale_with_cielo_split_needs_no_internal_repasse(): void
    {
        $fin = $this->userWithRole($this->company(), 'financeiro');
        $r = $this->receivable();
        $id = $this->as($this->clinic['admin'])->postJson('/api/v1/appointments', $this->bookPayload($this->patient('Outro'), '08:30'))->assertCreated()->json('data.id');
        $this->as($this->clinic['admin'])->postJson("/api/v1/appointments/{$id}/arrive")->assertCreated();
        $r2 = $this->context()->runFor($this->company()->id, fn () => Receivable::query()->where('appointment_id', $id)->firstOrFail());

        $this->actingAs($fin)->get(route('receivables.show', $r))->assertOk()->assertSee('Venda na maquininha Cielo com split');

        $this->as($fin)->postJson("/api/v1/receivables/{$r->id}/receive", ['method' => 'pix', 'amount_cents' => 25000, 'terminal_split' => true])
            ->assertUnprocessable()->assertJsonPath('code', 'terminal_split_invalid');
        $this->as($fin)->postJson("/api/v1/receivables/{$r->id}/receive", ['method' => 'credit_card', 'amount_cents' => 25000, 'terminal_split' => true])
            ->assertUnprocessable()->assertJsonPath('code', 'terminal_split_invalid');
        $this->as($fin)->postJson("/api/v1/receivables/{$r->id}/receive", ['method' => 'credit_card', 'amount_cents' => 25000, 'terminal_split' => true, 'authorization_code' => 'NSU 445566', 'card_installments' => 3])
            ->assertCreated();

        $split = DB::table('payment_splits')->first();
        $this->assertSame(['native', 'settled', 'cielo_terminal', 15000], [$split->mode, $split->status, $split->source, (int) $split->amount_cents]);
        $this->actingAs($fin)->post(route('splits.settle', $this->doctor), ['from' => '2026-10-01', 'to' => '2026-10-31'])->assertSessionHas('error'); // nada a repassar
        $this->get(route('splits.index', ['from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk()->assertSee('Maquininha Cielo');

        // Médico sem ID de subordinado: opção indisponível.
        $this->context()->runFor($this->company()->id, fn () => $this->doctor->forceFill(['cielo_subordinate_id' => null])->save());
        $this->as($fin)->postJson("/api/v1/receivables/{$r2->id}/receive", ['method' => 'debit_card', 'amount_cents' => 25000, 'terminal_split' => true, 'authorization_code' => 'NSU1'])
            ->assertUnprocessable()->assertJsonPath('code', 'terminal_split_unavailable');
    }

    public function test_gateway_and_doctor_configuration_screens(): void
    {
        $gw = $this->gateway();
        $this->actingAs($this->clinic['admin'])->get(route('gateways.index'))->assertOk()
            ->assertSee('API E-commerce')->assertSee($gw->webhookUrl().'?token='.$gw->webhook_token, false)->assertDontSee('mkey-secreta');
        $this->put(route('splits.wallet', $this->doctor), ['cielo_subordinate_id' => 'nao-uuid'])->assertSessionHasErrors('cielo_subordinate_id');
        $this->put(route('splits.wallet', $this->doctor), ['cielo_subordinate_id' => self::SUBORDINATE, 'asaas_wallet_id' => 'w-1'])->assertSessionHas('success');
        $this->assertSame([self::SUBORDINATE, 'w-1'], [DB::table('doctors')->where('id', $this->doctor->id)->value('cielo_subordinate_id'), DB::table('doctors')->where('id', $this->doctor->id)->value('asaas_wallet_id')]);
        $this->assertStringNotContainsString('mkey-secreta', (string) DB::table('payment_gateways')->value('credentials'));
    }
}
