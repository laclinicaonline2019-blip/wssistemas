<?php

namespace Tests\Feature;

use App\Core\Audit\AuditLogger;
use App\Core\Support\Money;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Entry;
use App\Models\UsageCounter;
use App\Models\WhatsappContact;
use App\Models\WhatsappMessage;
use App\Services\Assistant\AiInterpreter;
use App\Services\WhatsApp\WhatsAppClient;
use Carbon\Carbon;
use Tests\TestCase;

/** IA simulada SÓ no envio HTTP: limites do plano, contagem de tokens, recusa e leitura do JSON são o código real. */
class FakeAi extends AiInterpreter
{
    /** @var list<array|string> */
    public array $queue = [];

    public int $sent = 0;

    public function available(): bool
    {
        return true;
    }

    protected function send(?string $system, string $user, int $maxTokens, ?array $schema): ?array
    {
        $this->sent++;
        $next = array_shift($this->queue);
        if ($next === null) {
            return null;
        }

        return ['text' => is_string($next) ? $next : json_encode($next), 'input_tokens' => 120, 'output_tokens' => 30, 'stop_reason' => $next === 'REFUSAL' ? 'refusal' : 'end_turn'];
    }
}

/**
 * Integração WhatsApp ↔ WS Finanças (1.1.2): lançamentos e consultas pelo webhook assinado, com os mesmos dados do painel.
 */
class WhatsAppIntegrationTest extends TestCase
{
    private const PHONE = '5511987654321';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp.app_secret' => 'segredo-de-teste']);
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));
    }

    private function contact($account, string $phone = self::PHONE, array $extra = []): WhatsappContact
    {
        return $this->inAccount($account, fn () => WhatsappContact::query()->create(['label' => 'Dono', 'phone' => $phone, 'phone_hash' => WhatsappContact::hashPhone($phone),
            'phone_last4' => substr($phone, -4)] + $extra + ['can_write' => true, 'active' => true, 'verified_at' => now()]));
    }

    private function send(string $text, string $from = self::PHONE, ?string $wamid = null, ?int $timestamp = null)
    {
        $m = ['id' => $wamid ?? 'wamid.'.uniqid('', true), 'from' => $from, 'type' => 'text', 'text' => ['body' => $text]];
        if ($timestamp) {
            $m['timestamp'] = (string) $timestamp;
        }
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => ['messages' => [$m]]]]]]]);

        return $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'segredo-de-teste')], $body);
    }

    private function reply(): string
    {
        return end(WhatsAppClient::$sent)['body'] ?? '';
    }

    private function entries(?string $accountId = null)
    {
        return Entry::withoutGlobalScopes()->whereNull('deleted_at')->when($accountId, fn ($q) => $q->where('account_id', $accountId))->orderBy('created_at')->get();
    }

    // ============================================================ lançamentos

    public function test_100_de_combustivel_registra_despesa_paga_com_categoria_e_resposta_no_formato(): void
    {
        [$a] = $this->makeClient('profissional');
        $this->contact($a);
        $this->send('100 de combustível')->assertOk();

        $e = $this->entries()->sole();
        $this->assertSame($a->id, $e->account_id);
        $this->assertSame('despesa', $e->type);
        $this->assertSame(10000, $e->amount_cents);
        $this->assertSame('Combustível', $e->category);
        $this->assertSame(now()->toDateString(), $e->due_date->toDateString());
        $this->assertTrue($e->paid);
        $this->assertSame('whatsapp', $e->source);
        foreach (['✅ Despesa registrada com sucesso!', '⛽ Categoria: Combustível', '💰 Valor: '.Money::brl(10000), '📅 Data: '.now()->format('d/m/Y'), 'Seu controle financeiro já foi atualizado.'] as $line) {
            $this->assertStringContainsString($line, $this->reply());
        }

        // a data acompanha o dia do lançamento
        $this->travelTo(Carbon::parse('2026-11-20 08:00:00'));
        $this->send('100 de combustível');   // outro dia: não é repetição
        $this->assertStringContainsString('📅 Data: 20/11/2026', $this->reply());
    }

    public function test_exemplos_de_mensagens_geram_os_lancamentos_corretos(): void
    {
        [$a] = $this->makeClient('profissional');
        $this->contact($a);
        $cases = [
            ['Gastei 50 reais no almoço.', 'despesa', 5000, 'Refeições', '2026-11-16', true],
            ['Recebi 3.000 de salário.', 'receita', 300000, 'Salário', '2026-11-16', true],
            ['Paguei 120 de energia.', 'despesa', 12000, 'Contas da casa', '2026-11-16', true],
            ['Comprei 250 de supermercado.', 'despesa', 25000, 'Alimentação', '2026-11-16', true],
            ['Recebi 500 de um cliente.', 'receita', 50000, 'Recebimento de clientes', '2026-11-16', true],
            ['Gastei 80 de gasolina ontem.', 'despesa', 8000, 'Combustível', '2026-11-15', true],
        ];
        foreach ($cases as [$text, $type, $cents, $cat, $date, $paid]) {
            $this->send($text);
            $e = Entry::withoutGlobalScopes()->latest('created_at')->latest('id')->first();
            $this->assertSame([$type, $cents, $cat, $date, $paid], [$e->type, $e->amount_cents, $e->category, $e->due_date->toDateString(), $e->paid], $text);
            $this->assertStringContainsString($type === 'receita' ? 'Receita registrada com sucesso' : 'Despesa registrada com sucesso', $this->reply(), $text);
        }
        $this->assertCount(6, $this->entries());
    }

    public function test_lancamentos_do_whatsapp_aparecem_no_painel_relatorios_e_graficos(): void
    {
        [$a, $u, [$c]] = $this->makeClient('profissional');
        $this->contact($a);
        $this->send('100 de combustível');
        $this->send('Recebi 3.000 de salário.');

        $m = now()->format('Y-m');
        $itens = collect($this->actingAs($u)->getJson("/app/api/mes?empresa={$c->id}&mes=$m")->assertOk()->json('itens'))->whereIn('tipo', ['despesa', 'receita']);
        $this->assertSame(['Combustível', 'Salário'], $itens->pluck('desc')->sort()->values()->all());
        $rel = $this->actingAs($u)->getJson("/app/api/relatorio?empresa={$c->id}&de=$m&ate=$m")->assertOk();
        $rel->assertJsonPath('entradas', 3000)->assertJsonPath('saidas', 100);
        $this->assertSame('Combustível', $rel->json('por_categoria.0.categoria'));
        $serie = collect($this->actingAs($u)->getJson("/app/api/fluxo?empresa={$c->id}&mes=$m")->assertOk()->json('series'))->firstWhere('mes', $m);
        $this->assertEquals(2900, $serie['saldo']);
    }

    // ============================================================ consultas com dados reais

    public function test_consultas_respondem_com_os_dados_do_banco_inclusive_lancamentos_do_painel(): void
    {
        [$a, $u, [$c]] = $this->makeClient('profissional');
        $this->contact($a);
        // pelo painel
        $this->actingAs($u)->postJson('/app/api/lancamentos', ['empresa' => $c->id, 'tipo' => 'despesa', 'desc' => 'Posto Shell', 'valor' => 70, 'data' => '2026-11-03', 'pago' => true])->assertCreated();
        $this->actingAs($u)->postJson('/app/api/lancamentos', ['empresa' => $c->id, 'tipo' => 'despesa', 'desc' => 'Aluguel', 'valor' => 1200, 'data' => '2026-11-25'])->assertCreated();
        $this->actingAs($u)->postJson('/app/api/lancamentos', ['empresa' => $c->id, 'tipo' => 'receita', 'desc' => 'Freela', 'valor' => 800, 'data' => '2026-11-28'])->assertCreated();
        // pelo WhatsApp
        $this->send('100 de combustível');
        $this->send('Gastei 50 reais no almoço.');
        $this->send('Recebi 3.000 de salário.');
        $this->send('Gastei 80 de gasolina ontem.');

        $this->send('Quanto gastei hoje?');
        $this->assertStringContainsString('Você gastou '.Money::brl(15000).' hoje', $this->reply());

        $this->send('Quanto gastei de combustível este mês?');
        $this->assertStringContainsString('Você gastou '.Money::brl(25000).' com ⛽ Combustível neste mês', $this->reply(), 'inclui "Posto Shell" lançado no painel');

        $this->send('Qual foi minha despesa total?');
        $this->assertStringContainsString('Você gastou '.Money::brl(150000).' neste mês', $this->reply());
        $this->assertStringContainsString('em aberto '.Money::brl(120000), $this->reply());

        $this->send('Quanto recebi este mês?');
        $this->assertStringContainsString('Você recebeu '.Money::brl(300000).' neste mês', $this->reply());
        $this->assertStringContainsString('Ainda a receber neste mês: '.Money::brl(80000), $this->reply());

        $this->send('Qual meu saldo?');
        $this->assertStringContainsString('Entradas recebidas: '.Money::brl(300000), $this->reply());
        $this->assertStringContainsString('Despesas pagas: '.Money::brl(30000), $this->reply());
        $this->assertStringContainsString('Saldo: '.Money::brl(270000), $this->reply());
        $this->assertStringContainsString('Sobra prevista: '.Money::brl(380000 - 150000), $this->reply(), 'mesma conta do painel: entradas − contas do mês − atrasadas');

        $this->send('Me mostre minhas despesas por categoria.');
        $r = $this->reply();
        $this->assertStringContainsString('🏠 Moradia: '.Money::brl(120000), $r);
        $this->assertStringContainsString('⛽ Combustível: '.Money::brl(25000), $r);
        $this->assertStringContainsString('🍽️ Refeições: '.Money::brl(5000), $r);
        $this->assertStringContainsString('Total: '.Money::brl(150000), $r);

        $this->assertCount(7, $this->entries(), 'consultas não criam lançamentos');
    }

    public function test_sem_dados_nao_inventa_valores(): void
    {
        [$a] = $this->makeClient('profissional');
        $this->contact($a);
        foreach (['Quanto gastei hoje?', 'Quanto gastei de combustível este mês?', 'Quanto recebi este mês?', 'Me mostre minhas despesas por categoria.'] as $q) {
            $this->send($q);
            $this->assertStringContainsString('📭', $this->reply(), $q);
            $this->assertDoesNotMatchRegularExpression('/R\$\s?[1-9]/u', $this->reply(), $q);
        }
        $this->send('Qual meu saldo?');
        $this->assertStringContainsString('Saldo: '.Money::brl(0), $this->reply());
    }

    // ============================================================ duplicidade e isolamento

    public function test_sem_lancamentos_duplicados(): void
    {
        [$a] = $this->makeClient('profissional');
        $this->contact($a);
        $this->send('100 de combustível', wamid: 'wamid.A');
        $this->send('100 de combustível', wamid: 'wamid.A');   // reentrega da Meta
        $this->assertCount(1, $this->entries());
        $this->assertSame(1, WhatsappMessage::query()->where('wamid', 'wamid.A')->count());

        $this->send('100 de combustível', wamid: 'wamid.B');   // mesma mensagem enviada de novo pelo cliente
        $this->assertStringContainsString('Parece repetido', $this->reply());
        $this->send('não');
        $this->assertCount(1, $this->entries());
        $this->send('100 de combustível', wamid: 'wamid.C');
        $this->send('sim');                                    // confirmou que é outro abastecimento
        $this->assertCount(2, $this->entries());
    }

    public function test_isolamento_entre_clientes(): void
    {
        [$a] = $this->makeClient('profissional');
        [$b] = $this->makeClient('profissional');
        $this->contact($a, '5511911111111');
        $this->contact($b, '5521922222222');
        $this->send('100 de combustível', '5511911111111');
        $this->send('Recebi 3.000 de salário.', '5521922222222');
        $this->send('Gastei 999 no almoço', '5521922222222');

        $this->assertSame(['despesa'], $this->entries($a->id)->pluck('type')->all());
        $this->assertSame(['receita', 'despesa'], $this->entries($b->id)->pluck('type')->all());
        $this->assertTrue($this->entries($b->id)->every(fn ($e) => $this->inAccount($b, fn () => Company::query()->whereKey($e->company_id)->exists())));

        $this->send('Qual foi minha despesa total?', '5511911111111');
        $this->assertStringContainsString(Money::brl(10000), $this->reply());
        $this->assertStringNotContainsString('999', $this->reply());
        $this->send('Quanto recebi este mês?', '5511911111111');
        $this->assertStringContainsString('📭', $this->reply(), 'não enxerga a receita do outro cliente');
    }

    // ============================================================ ambiguidades

    public function test_ambiguidades_pedem_confirmacao_antes_de_registrar(): void
    {
        [$a] = $this->makeClient('profissional');
        $this->contact($a);

        $this->send('gastei 50 e 30 no mercado');                // dois valores
        $this->assertStringContainsString('mais de um valor', $this->reply());
        $this->assertCount(0, $this->entries());
        $this->send('2');
        $this->assertSame(3000, $this->entries()->sole()->amount_cents);

        $this->send('500 de salário');                            // receita ou despesa?
        $this->assertStringContainsString('Receita', $this->reply());
        $this->assertCount(1, $this->entries());
        $this->send('1');
        $this->assertSame('receita', $this->entries()->last()->type);

        $this->send('gastei 40 no mercado dia 20');               // dia 20 ainda não chegou → mês passado, com confirmação
        $this->assertStringContainsString('Confira a data', $this->reply());
        $this->assertStringContainsString('20/10/2026', $this->reply());
        $this->send('sim');
        $this->assertSame('2026-10-20', $this->entries()->last()->due_date->toDateString());

        $this->send('paguei 60 de farmácia 25/11');               // pagamento com data futura
        $this->assertStringContainsString('Confira a data', $this->reply());
        $this->send('não');

        $this->send('gastei no mercado');                         // sem valor
        $this->assertStringContainsString('Qual é o valor?', $this->reply());

        $this->send('gastei 45');                                 // sem descrição
        $this->assertStringContainsString('Confirma?', $this->reply());
        $this->send('padaria');
        $this->assertSame('Padaria', $this->entries()->last()->description);

        $this->send('fiz uma compra estranha');                   // sem IA e sem entender
        $this->assertStringContainsString('Não entendi', $this->reply());
        $this->assertCount(4, $this->entries());
    }

    public function test_data_da_mensagem_entregue_com_atraso(): void
    {
        [$a] = $this->makeClient('profissional');
        $this->contact($a);
        $this->send('100 de combustível', timestamp: Carbon::parse('2026-11-15 22:30:00')->timestamp);
        $this->assertSame('2026-11-15', $this->entries()->sole()->due_date->toDateString());
    }

    // ============================================================ segurança

    public function test_numero_precisa_ser_validado_pelo_proprio_celular(): void
    {
        [$a, $u] = $this->makeClient('profissional');
        $this->actingAs($u)->post('/app/whatsapp', ['phone' => '(11) 98765-4321', 'label' => 'Celular do dono', 'can_write' => '1'])->assertSessionHas('wa_code');
        $code = session('wa_code')['code'];
        $c = WhatsappContact::withoutGlobalScopes()->sole();
        $this->assertNull($c->verified_at);
        $this->assertNotSame($code, $c->getAttributes()['verify_code_hash'], 'só o hash do código fica no banco');
        $this->assertSame(64, strlen((string) $c->getAttributes()['verify_code_hash']));

        $this->send('100 de combustível');
        $this->assertStringContainsString('aguardando validação', $this->reply());
        $this->send('000000');
        $this->assertCount(0, $this->entries());

        $this->send('meu código é '.substr($code, 0, 3).' '.substr($code, 3));
        $this->assertStringContainsString('Número validado', $this->reply());
        $this->assertNotNull($c->fresh()->verified_at);
        $this->assertSame('whatsapp.validar', AuditLog::query()->orderByDesc('id')->value('action'));

        $this->send('100 de combustível');
        $this->assertCount(1, $this->entries());
        $this->actingAs($u)->get('/app/whatsapp')->assertOk()->assertSee('validado');
    }

    public function test_codigo_errado_bloqueia_apos_tentativas_e_novo_codigo_libera(): void
    {
        [$a, $u] = $this->makeClient('profissional');
        $this->actingAs($u)->post('/app/whatsapp', ['phone' => self::PHONE, 'label' => 'Dono', 'can_write' => '1']);
        $c = WhatsappContact::withoutGlobalScopes()->sole();
        for ($i = 0; $i < WhatsappContact::VERIFY_MAX_ATTEMPTS; $i++) {
            $this->send('123 999');
        }
        $this->assertStringContainsString('Muitas tentativas', $this->reply());
        $this->actingAs($u)->post('/app/whatsapp/'.$c->id.'/codigo')->assertSessionHas('wa_code');
        $this->send(session('wa_code')['code']);
        $this->assertStringContainsString('Número validado', $this->reply());

        // código expira em 24 h
        [$a2, $u2] = $this->makeClient('profissional');
        $this->actingAs($u2)->post('/app/whatsapp', ['phone' => '5531933333333', 'label' => 'Outro', 'can_write' => '1']);
        $code = session('wa_code')['code'];
        $this->travel(25)->hours();
        $this->send($code, '5531933333333');
        $this->assertStringContainsString('expirou', $this->reply());
    }

    public function test_webhook_sem_assinatura_valida_e_recusado(): void
    {
        [$a] = $this->makeClient('profissional');
        $this->contact($a);
        $body = json_encode(['entry' => [['changes' => [['value' => ['messages' => [['id' => 'w1', 'from' => self::PHONE, 'type' => 'text', 'text' => ['body' => '100 de combustível']]]]]]]]]);
        $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'outro')], $body)->assertStatus(401);
        $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertStatus(401);
        $this->assertCount(0, $this->entries());
    }

    public function test_limite_por_numero_e_numero_desconhecido(): void
    {
        [$a] = $this->makeClient('profissional');
        $this->contact($a);
        config(['services.whatsapp.rate_per_5min' => 5]);
        for ($i = 0; $i < 7; $i++) {
            $this->send('quanto gastei hoje?');
        }
        $this->assertSame(2, WhatsappMessage::query()->where('status', 'rate_limited')->count());
        $this->assertSame(5, WhatsappMessage::query()->where('direction', 'out')->count());

        WhatsAppClient::$sent = [];
        $this->send('100 de combustível', '5599900000000');
        $this->send('oi', '5599900000000');
        $this->assertCount(1, WhatsAppClient::$sent, 'número desconhecido: no máximo uma resposta por dia');
        $this->assertCount(0, $this->entries(), 'número desconhecido nunca lança');
    }

    public function test_numero_so_consulta_nao_lanca_e_tudo_fica_na_auditoria(): void
    {
        [$a] = $this->makeClient('profissional');
        $this->contact($a, extra: ['can_write' => false]);
        $this->send('100 de combustível');
        $this->assertStringContainsString('permissão de consulta', $this->reply());
        $this->assertCount(0, $this->entries());

        [$b] = $this->makeClient('profissional');
        $this->contact($b, '5521922222222');
        $this->send('100 de combustível', '5521922222222');
        $log = AuditLog::query()->where('action', 'lancamento.criar')->sole();
        $this->assertSame('whatsapp', $log->channel);
        $this->assertSame($b->id, $log->account_id);
        $this->assertTrue(AuditLogger::verify()['ok']);
    }

    // ============================================================ IA e consumo

    public function test_ia_interpreta_quando_as_regras_nao_entendem_e_sempre_confirma(): void
    {
        $ai = new FakeAi;
        $this->app->instance(AiInterpreter::class, $ai);
        [$a] = $this->makeClient('profissional');
        $this->contact($a);

        $ai->queue[] = ['intent' => 'despesa', 'amount' => 89.9, 'date' => '2026-11-16', 'paid' => true, 'description' => 'Tênis de corrida', 'company' => null,
            'installments' => null, 'recurring' => false, 'category' => 'Compras', 'period' => null, 'ambiguous' => false];
        $this->send('torrei oitenta e nove e noventa num tênis de corrida');
        $this->assertStringContainsString('Confirma?', $this->reply());
        $this->assertCount(0, $this->entries(), 'resultado da IA nunca é lançado sem confirmação');
        $this->send('sim');
        $e = $this->entries()->sole();
        $this->assertSame([8990, 'Compras'], [$e->amount_cents, $e->category]);
        $this->assertTrue(WhatsappMessage::query()->where('direction', 'in')->where('used_ai', true)->exists());

        $u = UsageCounter::current($a->id);
        $this->assertSame([1, 120, 30], [(int) $u->ai_calls, (int) $u->ai_input_tokens, (int) $u->ai_output_tokens]);
    }

    public function test_ia_so_extrai_a_pergunta_e_os_numeros_vem_do_banco(): void
    {
        $ai = new FakeAi;
        $this->app->instance(AiInterpreter::class, $ai);
        [$a] = $this->makeClient('profissional');
        $this->contact($a);
        $this->send('100 de combustível');
        $this->send('Gastei 80 de gasolina ontem.');
        $this->assertSame(0, $ai->sent, 'mensagens claras não gastam IA');

        $ai->queue[] = ['intent' => 'consulta_gastos', 'amount' => 99999, 'date' => null, 'paid' => false, 'description' => '', 'company' => null,
            'installments' => null, 'recurring' => false, 'category' => 'Combustível', 'period' => 'mes', 'ambiguous' => false];
        $this->send('e aí, qto foi de posto nesse mês');
        $this->assertStringContainsString('Você gastou '.Money::brl(18000).' com ⛽ Combustível', $this->reply());
        $this->assertStringNotContainsString('99.999', $this->reply(), 'valor vindo da IA é ignorado nas consultas');
    }

    public function test_ia_classifica_categoria_desconhecida_e_respeita_limite_do_plano(): void
    {
        $ai = new FakeAi;
        $this->app->instance(AiInterpreter::class, $ai);
        [$a] = $this->makeClient('profissional');
        $this->contact($a);

        $ai->queue[] = ['category' => 'Lazer'];
        $this->send('paguei 120 de paintball');
        $this->assertSame('Lazer', $this->entries()->sole()->category);
        $ai->queue[] = ['category' => 'Categoria Inventada'];
        $this->send('paguei 30 de xpto');
        $this->assertSame('Outros', $this->entries()->last()->category, 'categoria fora da lista é descartada');

        $ai->queue[] = 'REFUSAL';
        $this->send('paguei 15 de abcde');
        $this->assertSame('Outros', $this->entries()->last()->category);

        // limite mensal de chamadas de IA do plano atingido → nenhuma chamada
        UsageCounter::bump($a->id, ['ai_calls' => 1000]);
        $before = $ai->sent;
        $this->send('paguei 22 de qwerty');
        $this->send('fiz uma coisa estranha');
        $this->assertSame($before, $ai->sent);
        $this->assertStringContainsString('Não entendi', $this->reply());
    }
}
