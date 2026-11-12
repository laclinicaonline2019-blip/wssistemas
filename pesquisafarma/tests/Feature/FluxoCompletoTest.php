<?php

namespace Tests\Feature;

use App\Http\Controllers\Staff\ReportController;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Pharmacy;
use App\Models\Promotion;
use App\Models\Quote;
use App\Models\QuotePharmacy;
use App\Models\Receipt;
use App\Models\Reward;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\FollowUpService;
use App\Services\LoyaltyService;
use App\Services\QuoteService;
use App\Services\ReceiptService;
use App\Services\WhatsApp\MockGateway;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Do primeiro "oi" no WhatsApp até a comissão da farmácia e os pontos do cliente. */
class FluxoCompletoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        MockGateway::$sent = [];
    }

    private function whatsapp(string $phone, ?string $text, ?string $image = null): void
    {
        $this->postJson('/webhook/whatsapp/segredo-teste', array_filter(['phone' => $phone, 'text' => $text, 'image_base64' => $image ? base64_encode($image) : null, 'id' => uniqid('m')]))->assertOk();
    }

    private function lastSentTo(string $phone): string
    {
        $msgs = array_values(array_filter(MockGateway::$sent, fn ($m) => $m['phone'] === $phone));

        return end($msgs)['text'] ?? '';
    }

    public function test_webhook_rejeita_segredo_errado(): void
    {
        $this->postJson('/webhook/whatsapp/errado', ['phone' => '11999990000', 'text' => 'oi'])->assertNotFound();
    }

    public function test_fluxo_completo_cotacao_pedido_nota_comissao_e_pontos(): void
    {
        $phone = '5511977776666';

        // 1) Primeiro contato já mandando a lista → cadastro obrigatório
        $this->whatsapp($phone, "Bom dia\n2x Losartana 50mg genérico\nDipirona 500mg");
        $this->assertStringContainsString('nome completo', $this->lastSentTo($phone));
        $this->assertSame(0, Quote::count(), 'a cotação só nasce depois do cadastro');

        $this->whatsapp($phone, 'maria teste da silva');
        $this->assertStringContainsString('CEP', $this->lastSentTo($phone));
        $this->whatsapp($phone, 'Bela Vista');
        $this->assertStringContainsString('endereço', $this->lastSentTo($phone));
        $this->whatsapp($phone, 'Rua das Flores, 50 apto 3');
        $this->whatsapp($phone, 'sim');
        $this->whatsapp($phone, 'sim');

        $customer = Customer::where('phone', $phone)->first();
        $this->assertTrue($customer->isRegistered());
        $this->assertSame('Maria Teste Da Silva', $customer->name);
        $this->assertTrue($customer->accepts_marketing);
        $this->assertNotNull($customer->lgpd_consent_at);

        // 2) A mensagem guardada vira cotação com os itens separados
        $quote = Quote::with('items')->firstOrFail();
        $this->assertSame($customer->id, $quote->customer_id);
        $this->assertCount(2, $quote->items);
        $losartana = $quote->items->firstWhere('description', 'Losartana');
        $this->assertSame(2, $losartana->quantity);
        $this->assertSame('generico', $losartana->preference);
        $this->assertSame('50mg', $losartana->presentation);

        // 3) Televendas abre: farmácias mais próximas do bairro primeiro
        $tele = User::where('email', 'televendas1@demo.local')->first();
        $page = $this->actingAs($tele)->get(route('quotes.show', $quote))->assertOk();
        $page->assertSee('Drogaria Bem Estar')->assertSee('Mais distantes');
        $this->assertSame($tele->id, $quote->fresh()->operator_id);

        $near = Pharmacy::whereIn('trade_name', ['Drogaria Bem Estar', 'Farma Popular Liberdade', 'Farmácia Saúde Já'])->pluck('id')->all();
        $far = Pharmacy::where('trade_name', 'Farmácia Moema 24h')->value('id');
        $this->post(route('quotes.dispatch', $quote), ['pharmacies' => [...$near, $far]])->assertSessionHas('ok');
        $quote->refresh();
        $this->assertSame('enviada', $quote->status);
        $this->assertSame(4, $quote->dispatches()->count());
        $this->assertNotNull($quote->dispatched_at);
        $bemEstar = QuotePharmacy::whereHas('pharmacy', fn ($q) => $q->where('trade_name', 'Drogaria Bem Estar'))->first();
        $this->assertTrue($bemEstar->delivers, 'farmácia do mesmo bairro entrega');
        $liberdade = QuotePharmacy::whereHas('pharmacy', fn ($q) => $q->where('trade_name', 'Farma Popular Liberdade'))->first();
        $this->assertFalse($liberdade->delivers, 'farmácia que não entrega');

        // 4) Farmácias respondem pelo link (sem login)
        auth()->logout();
        [$losId, $dipId] = [$losartana->id, $quote->items->firstWhere('description', 'Dipirona')->id];
        $this->get('/f/'.$bemEstar->token)->assertOk()->assertSee('Losartana');
        $this->post('/f/'.$bemEstar->token, ['offers' => [
            $losId => [['type' => 'referencia', 'price' => '45,90'], ['type' => 'generico', 'price' => '12,50', 'laboratory' => 'EMS']],
            $dipId => [['type' => 'generico', 'price' => '6,00']],
        ], 'delivers' => '1', 'delivery_fee' => '5,00', 'suggestions' => [['product_name' => 'Vitamina C 1g', 'price' => '19,90']]])->assertSessionHas('ok');

        $this->post('/f/'.$liberdade->token, ['offers' => [
            $losId => [['type' => 'generico', 'price' => '10,00']], $dipId => [['type' => 'generico', 'price' => '5,00']],
        ]])->assertSessionHas('ok');
        $saude = QuotePharmacy::whereHas('pharmacy', fn ($q) => $q->where('trade_name', 'Farmácia Saúde Já'))->first();
        $this->post('/f/'.$saude->token.'/recusar', ['reason' => 'sem estoque'])->assertSessionHas('ok');
        // Só referência para a losartana → não atende a preferência "genérico"
        $moema = QuotePharmacy::where('pharmacy_id', $far)->first();
        $this->post('/f/'.$moema->token, ['offers' => [$losId => [['type' => 'referencia', 'price' => '9,00']], $dipId => [['type' => 'generico', 'price' => '4,00']]], 'delivers' => '1', 'delivery_fee' => '15'])->assertSessionHas('ok');

        $quote->refresh();
        $this->assertSame('respondida', $quote->status);
        $this->assertNotNull($quote->first_response_at);

        // 5) Ranking: mais itens; cliente quer entrega → quem entrega primeiro; depois menor total
        $ranking = app(QuoteService::class)->ranking($quote);
        $this->assertSame('Drogaria Bem Estar', $ranking[0]['dispatch']->pharmacy->trade_name); // 2x12,50 + 6 + 5 entrega = 36
        $this->assertEqualsWithDelta(36.0, $ranking[0]['total'], 0.001);
        $this->assertSame('Farma Popular Liberdade', $ranking[1]['dispatch']->pharmacy->trade_name); // 25, mas só retirada
        $this->assertEqualsWithDelta(25.0, $ranking[1]['total'], 0.001);
        $this->assertSame('Farmácia Moema 24h', $ranking[2]['dispatch']->pharmacy->trade_name); // só 1 item atende a preferência
        $this->assertSame(1, $ranking[2]['items_available']);

        // 6) Apresenta ao cliente; cliente escolhe a opção 1 pelo WhatsApp
        $this->actingAs($tele)->post(route('quotes.present', $quote))->assertSessionHas('ok');
        $this->assertStringContainsString('🥇 *Drogaria Bem Estar*', $this->lastSentTo($phone));
        $this->assertStringContainsString('Farma Popular Liberdade', $this->lastSentTo($phone));
        auth()->logout();
        $this->whatsapp($phone, '1');
        $order = Order::with('items')->firstOrFail();
        $this->assertSame($bemEstar->pharmacy_id, $order->pharmacy_id);
        $this->assertEqualsWithDelta(36.0, $order->total, 0.001);
        $this->assertSame(6.0, $order->commission_percent);
        $this->assertEqualsWithDelta(2.16, $order->commission_amount, 0.001);
        $this->assertSame('pedido', $quote->fresh()->status);
        $this->assertNotNull($order->followup_at, 'pós-venda agendado');

        // 7) Pós-venda: UMA mensagem com as 3 perguntas (chegou? indicaria? nota fiscal)
        $this->travel(3)->hours();
        $this->artisan('pf:pos-venda')->assertSuccessful();
        $survey = $this->lastSentTo($phone);
        $this->assertStringContainsString('chegou? *S* ou *N*', $survey);
        $this->assertStringContainsString('0 a 10', $survey);
        $this->assertStringContainsString('foto da nota fiscal', $survey);
        auth()->logout();
        $this->whatsapp($phone, 'S 10');
        $quote->refresh();
        $order->refresh();
        $this->assertSame('entregue', $order->status);
        $this->assertNotNull($order->delivery_confirmed_at);
        $this->assertSame('finalizada', $quote->status);
        $this->assertSame(10, $quote->nps_score);
        $this->assertStringContainsString('foto da nota', $this->lastSentTo($phone));

        // 8) Cliente manda só a foto (sem escrever NOTA) → captação da venda
        $this->whatsapp($phone, null, 'fake-image-bytes');
        $receipt = Receipt::firstOrFail();
        $this->assertSame('pendente', $receipt->status);
        $this->assertSame($order->id, $receipt->order_id, 'liga automaticamente ao pedido recente');

        $this->actingAs($tele)->post(route('receipts.verify', $receipt), [
            'pharmacy_id' => $bemEstar->pharmacy_id, 'total' => '60,00', 'order_id' => $order->id,
            'items' => [['description' => 'LOSARTANA POTASSICA 50MG EMS', 'quantity' => 2, 'unit_price' => '12,50', 'total' => '25,00'],
                ['description' => 'DIPIRONA 500MG', 'quantity' => 1, 'unit_price' => '6,00', 'total' => '6,00'],
                ['description' => 'VITAMINA C 1G EFERV', 'quantity' => 1, 'unit_price' => '29,00', 'total' => '29,00']],
        ])->assertRedirect();
        $receipt->refresh();
        $order->refresh();
        $this->assertSame('conferida', $receipt->status);
        // A comissão passa a incidir sobre tudo o que foi comprado (nota)
        $this->assertEqualsWithDelta(60.0, $order->commission_base, 0.001);
        $this->assertEqualsWithDelta(3.6, $order->commission_amount, 0.001);
        // Pontos: 1/R$ (60) + bônus vitamina (50)
        $this->assertSame(110, $receipt->points_awarded);
        $this->assertSame(110, $customer->fresh()->points_balance);
        $matched = $receipt->items()->whereNotNull('quote_item_id')->count();
        $this->assertSame(2, $matched, 'losartana e dipirona reconhecidas como itens cotados');
        $this->assertEqualsWithDelta(24.0, ReceiptService::comparison($receipt->fresh(['order', 'items']))['difference'], 0.001);

        // 9) Relatórios por farmácia e por cliente
        $this->get(route('reports', 'farmacias'))->assertOk()->assertSee('Drogaria Bem Estar');
        $this->get(route('reports', 'clientes'))->assertOk()->assertSee('Maria Teste Da Silva');
        $this->get(route('reports', 'cotado-comprado'))->assertOk()->assertSee($receipt->code);
        $this->get(route('reports', ['televendas', 'csv' => 1]))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->get(route('reports', 'tempos'))->assertOk();

        // 10) Fechamento de comissão (Bem Estar é semanal)
        $st = app(CommissionService::class)->generate(Pharmacy::find($bemEstar->pharmacy_id), CarbonImmutable::today()->subDays(7), CarbonImmutable::today());
        $this->assertEqualsWithDelta(3.6, $st->commission_amount, 0.001);
        $this->assertSame($st->id, $order->fresh()->commission_statement_id);
        $this->expectExceptionMessage('Já existe fechamento');
        app(CommissionService::class)->generate(Pharmacy::find($bemEstar->pharmacy_id), CarbonImmutable::today(), CarbonImmutable::today());
    }

    private function presentedQuoteWithOrder(): array
    {
        $customer = Customer::first();
        $svc = app(QuoteService::class);
        $quote = $svc->createFromMessage($customer, 'Omeprazol 20mg', channel: 'telefone');
        $pharmacy = Pharmacy::where('trade_name', 'Drogaria Bem Estar')->first();
        $svc->dispatch($quote, [$pharmacy->id]);
        $d = $quote->dispatches()->first();
        $svc->respond($d, ['offers' => [$quote->items[0]->id => [['type' => 'generico', 'price' => '15']]], 'delivers' => '1', 'delivery_fee' => '5']);
        $svc->present($quote->fresh());

        return [$customer, $quote->fresh(), $d->fresh()];
    }

    public function test_pos_venda_nao_chegou_avisa_farmacia_e_pergunta_de_novo(): void
    {
        [$customer, $quote, $d] = $this->presentedQuoteWithOrder();
        $this->whatsapp($customer->phone, '1');
        $order = Order::firstOrFail();
        app(FollowUpService::class)->askPostSale($customer->fresh(), $quote, $order, askDelivery: true);

        $this->whatsapp($customer->phone, 'N 4 o motoboy não apareceu');
        $order->refresh();
        $this->assertTrue($order->hasOpenIssue());
        $this->assertStringContainsString('motoboy', $order->delivery_issue);
        $this->assertSame(4, $quote->fresh()->nps_score);
        $this->assertNotNull($order->followup_at, 'pergunta de novo em 1 hora');
        $pharmacyMsgs = array_filter(MockGateway::$sent, fn ($m) => $m['phone'] === $d->pharmacy->whatsapp && str_contains($m['text'], 'não recebeu'));
        $this->assertNotEmpty($pharmacyMsgs, 'farmácia avisada');
        $admin = User::where('role', 'admin')->first();
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertSee('Entregas com problema');

        // Respostas em partes: só a entrega agora
        $this->travel(61)->minutes();
        $this->artisan('pf:pos-venda');
        auth()->logout();
        $this->whatsapp($customer->phone, 'sim');
        $this->assertSame('entregue', $order->fresh()->status);
        $this->assertFalse($order->fresh()->hasOpenIssue());
    }

    public function test_pos_venda_respostas_separadas_e_cliente_que_comprou_sem_pedido(): void
    {
        [$customer, $quote] = $this->presentedQuoteWithOrder();
        $this->travel(4)->hours();
        $this->artisan('pf:pos-venda');
        $this->assertStringContainsString('COMPREI', $this->lastSentTo($customer->phone));
        $this->whatsapp($customer->phone, 'comprei');
        $quote->refresh();
        $this->assertSame('comprou', $quote->customer_reported);
        $this->assertSame('finalizada', $quote->status);
        $msg = $this->lastSentTo($customer->phone);
        $this->assertStringNotContainsString('chegou?', $msg);
        $this->assertStringContainsString('0 a 10', $msg);
        // Manda a foto antes de responder a nota → registra a nota e cobra só a indicação
        $this->whatsapp($customer->phone, null, 'foto');
        $this->assertSame(1, Receipt::count());
        $this->assertStringContainsString('0 a 10', $this->lastSentTo($customer->phone));
        $this->whatsapp($customer->phone, '8');
        $this->assertSame(8, $quote->fresh()->nps_score);
        $this->assertNull($customer->fresh()->bot_state);
    }

    public function test_nota_avulsa_gera_comissao_propria_e_troca_de_brinde(): void
    {
        $customer = Customer::first();
        $pharmacy = Pharmacy::where('trade_name', 'Farmácia Saúde Já')->first();
        $admin = User::where('role', 'admin')->first();
        $this->actingAs($admin)->post(route('receipts.store'), ['customer_id' => $customer->id, 'file' => UploadedFile::fake()->image('nota.jpg')])->assertRedirect();
        $receipt = Receipt::first();
        $this->post(route('receipts.verify', $receipt), ['pharmacy_id' => $pharmacy->id, 'total' => '200,00', 'order_id' => ''])->assertRedirect();
        $receipt->refresh();
        $this->assertNull($receipt->order_id);
        $this->assertEqualsWithDelta(10.0, $receipt->commission_amount, 0.001); // 5% de 200
        $this->assertSame(200, $customer->fresh()->points_balance);

        $st = app(CommissionService::class)->generate($pharmacy, CarbonImmutable::today()->startOfMonth(), CarbonImmutable::today());
        $this->assertEqualsWithDelta(10.0, $st->commission_amount, 0.001);
        $this->assertSame(1, $st->sales_count);

        $squeeze = Reward::where('name', 'Squeeze Pesquisa Farma')->first();
        app(LoyaltyService::class)->redeem($customer->fresh(), $squeeze);
        $this->assertSame(50, $customer->fresh()->points_balance);
        $this->assertSame(49, $squeeze->fresh()->stock);

        $kit = Reward::where('name', 'Kit higiene')->first(); // exige 2 compras e 400 pts
        $this->expectExceptionMessage('Pontos insuficientes');
        app(LoyaltyService::class)->redeem($customer->fresh(), $kit);
    }

    public function test_farmacia_so_ve_e_responde_as_proprias_cotacoes(): void
    {
        $customer = Customer::first();
        $quote = app(QuoteService::class)->createFromMessage($customer, "Amoxicilina 500mg\nOmeprazol 20mg", channel: 'telefone');
        $mine = Pharmacy::where('trade_name', 'Farmácia Saúde Já')->first();
        $other = Pharmacy::where('trade_name', 'Drogaria Bem Estar')->first();
        app(QuoteService::class)->dispatch($quote, [$mine->id, $other->id]);
        $user = User::where('email', 'farmacia@demo.local')->first();
        $dMine = QuotePharmacy::where('pharmacy_id', $mine->id)->first();
        $dOther = QuotePharmacy::where('pharmacy_id', $other->id)->first();

        $this->actingAs($user)->get(route('panel.home'))->assertOk()->assertSee($quote->code);
        $this->get(route('panel.quote', $dOther))->assertForbidden();
        $this->get(route('dashboard'))->assertForbidden();
        $this->post(route('panel.respond', $dMine), ['offers' => [$quote->items[0]->id => [['type' => 'generico', 'price' => '18,00']]]])->assertRedirect(route('panel.home'));
        $this->assertSame('respondida', $dMine->fresh()->status);
        $this->post(route('panel.promotions.store'), ['title' => 'Dermocosméticos 20% OFF', 'price' => '39,90'])->assertSessionHas('ok');
        $this->assertDatabaseHas('promotions', ['title' => 'Dermocosméticos 20% OFF', 'status' => 'pendente', 'pharmacy_id' => $mine->id]);
    }

    public function test_promocao_aprovada_e_divulgada_so_para_quem_aceita(): void
    {
        $admin = User::where('role', 'admin')->first();
        Customer::first()->update(['accepts_marketing' => false]);
        $promo = Promotion::first();
        $this->actingAs($admin)->post(route('promotions.status', $promo), ['status' => 'aprovada']);
        $this->post(route('promotions.broadcast', $promo), ['scope' => 'todos'])->assertSessionHas('ok');
        $this->assertSame(3, $promo->fresh()->broadcast_count);
        $this->assertSame('divulgada', $promo->fresh()->status);
        $this->assertStringContainsString('SAIR', MockGateway::$sent[0]['text']);
    }

    public function test_area_do_cliente_login_por_codigo_no_whatsapp(): void
    {
        $customer = Customer::first();
        $this->post(route('portal.code'), ['phone' => $customer->phone])->assertSessionHas('code_sent');
        preg_match('/\*(\d{6})\*/', MockGateway::$sent[0]['text'], $m);
        $this->post(route('portal.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post(route('portal.verify'), ['code' => $m[1]])->assertRedirect(route('portal.home'));
        $this->get(route('portal.home'))->assertOk()->assertSee('Brindes')->assertSee('Enviar nota fiscal')->assertSee('Cartão Fidelidade')->assertSee('Faltam');
        $this->post(route('portal.receipt'), ['file' => UploadedFile::fake()->image('cupom.png')])->assertSessionHas('ok');
        $this->assertSame('portal', Receipt::first()->source);
    }

    public function test_cartao_fidelidade_robo_pontos_e_botao_do_televendas(): void
    {
        $customer = Customer::first();
        app(LoyaltyService::class)->add($customer, 120, 'Compra conferida');
        $this->whatsapp($customer->phone, 'Quantos pontos eu tenho?');
        $msg = $this->lastSentTo($customer->phone);
        $this->assertStringContainsString('Cartão Fidelidade', $msg);
        $this->assertStringContainsString('*120 pontos*', $msg);
        $this->assertStringContainsString('Faltam *30 pontos* para: Squeeze Pesquisa Farma', $msg);
        $this->assertSame(0, Quote::count(), 'pergunta de pontos não vira cotação');

        $tele = User::where('role', 'televendas')->first();
        $this->actingAs($tele)->get(route('inbox.show', $customer))->assertOk()->assertSee('Cartão Fidelidade')->assertSee('PF 000001');
        MockGateway::$sent = [];
        $this->post(route('inbox.card', $customer))->assertSessionHas('ok');
        $this->assertStringContainsString('120 pontos', MockGateway::$sent[0]['text']);
    }

    public function test_todas_as_telas_abrem(): void
    {
        $admin = User::where('role', 'admin')->first();
        $this->actingAs($admin);
        $customer = Customer::first();
        $quote = app(QuoteService::class)->createFromMessage($customer, 'Rivotril 2mg', channel: 'telefone');
        foreach (['dashboard', 'inbox', 'quotes.index', 'quotes.create', 'orders.index', 'customers.index', 'customers.create', 'receipts.index', 'promotions.index',
            'commissions.index', 'loyalty', 'activity', 'pharmacies.index', 'pharmacies.create', 'neighborhoods.index', 'users.index', 'settings'] as $r) {
            $this->get(route($r))->assertOk();
        }
        $this->get(route('quotes.show', $quote))->assertOk();
        $this->get(route('customers.show', $customer))->assertOk();
        $this->get(route('customers.edit', $customer))->assertOk();
        $this->get(route('inbox.show', $customer))->assertOk();
        $this->get(route('pharmacies.show', Pharmacy::first()))->assertOk();
        $this->get(route('pharmacies.edit', Pharmacy::first()))->assertOk();
        foreach (array_keys(ReportController::REPORTS) as $rep) {
            $this->get(route('reports', $rep))->assertOk();
        }
        $tele = User::where('role', 'televendas')->first();
        $this->actingAs($tele)->get(route('settings'))->assertForbidden();
        $this->get(route('pharmacies.index'))->assertForbidden();
    }
}
