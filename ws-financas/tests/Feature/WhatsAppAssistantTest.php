<?php

namespace Tests\Feature;

use App\Models\Entry;
use App\Models\WhatsappContact;
use App\Models\WhatsappMessage;
use App\Services\Billing\BillingService;
use App\Services\WhatsApp\WhatsAppClient;
use Tests\TestCase;

class WhatsAppAssistantTest extends TestCase
{
    private function authorize($account, string $phone = '5511987654321', ?string $defaultCompany = null): WhatsappContact
    {
        return $this->inAccount($account, fn () => WhatsappContact::query()->create(['label' => 'Dono', 'phone' => $phone, 'phone_hash' => WhatsappContact::hashPhone($phone),
            'phone_last4' => substr($phone, -4), 'default_company_id' => $defaultCompany, 'can_write' => true, 'active' => true]));
    }

    private function send(string $text, string $from = '5511987654321', ?string $wamid = null, bool $sign = true)
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => ['messages' => [[
            'id' => $wamid ?? 'wamid.'.uniqid(), 'from' => $from, 'type' => 'text', 'text' => ['body' => $text]]]]]]]]]);
        $sig = 'sha256='.hash_hmac('sha256', $body, $sign ? 'segredo-de-teste' : 'errado');

        return $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $sig], $body);
    }

    private function lastReply(): string
    {
        return end(WhatsAppClient::$sent)['body'] ?? '';
    }

    public function test_verificacao_e_assinatura_do_webhook(): void
    {
        $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=verificar-teste&hub_challenge=123')->assertOk()->assertSee('123');
        $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=errado&hub_challenge=123')->assertForbidden();
        $this->send('oi', sign: false)->assertStatus(401);
    }

    public function test_numero_nao_autorizado(): void
    {
        $this->send('paguei 100 de luz', '5511900000000')->assertOk();
        $this->assertStringContainsString('não está autorizado', $this->lastReply());
        $this->assertSame(0, Entry::withoutGlobalScopes()->count());
    }

    public function test_exemplos_do_cliente_pelo_whatsapp(): void
    {
        [$a, , [$ca, $cb]] = $this->makeClient('profissional', ['Empresa A', 'Empresa B']);
        $this->authorize($a);

        $this->send('Paguei R$ 350 de energia da empresa A.');
        $e = Entry::withoutGlobalScopes()->first();
        $this->assertSame($ca->id, $e->company_id);
        $this->assertTrue($e->paid);
        $this->assertSame(35000, $e->amount_cents);
        $this->assertSame('whatsapp', $e->source);
        $this->assertStringContainsString('Despesa lançada', $this->lastReply());

        // sem empresa e sem padrão → pergunta qual
        $this->send('Recebi R$ 5.000 de um cliente.');
        $this->assertStringContainsString('Para qual empresa', $this->lastReply());
        $this->send('2');
        $this->assertSame(1, Entry::withoutGlobalScopes()->where('company_id', $cb->id)->where('type', 'receita')->count());

        // sem descrição → confirma; a resposta vira a descrição
        $this->send('Cadastre uma conta de R$ 1.200 para pagar dia 20 na empresa A');
        $this->assertStringContainsString('Confirma?', $this->lastReply());
        $this->send('aluguel da loja');
        $bill = Entry::withoutGlobalScopes()->where('amount_cents', 120000)->first();
        $this->assertSame('Aluguel da loja', $bill->description);
        $this->assertFalse($bill->paid);

        $this->send('Quanto tenho para pagar '.$bill->due_date->format('d/m').'?');
        $this->send('contas vencidas?');
        $this->send('resumo do mês');
        $this->assertStringContainsString('Resumo', $this->lastReply());
    }

    public function test_desfazer_corrigir_e_duplicidade(): void
    {
        [$a, , [$ca]] = $this->makeClient('profissional');
        $this->authorize($a, defaultCompany: $ca->id);

        $this->send('mercado 380 paguei');
        $this->send('corrigir para 390');
        $this->assertSame(39000, Entry::withoutGlobalScopes()->first()->amount_cents);
        $this->send('mercado 390 paguei');
        $this->assertStringContainsString('Parece repetido', $this->lastReply());
        $this->send('não');
        $this->assertSame(1, Entry::withoutGlobalScopes()->count());
        $this->send('DESFAZER');
        $this->assertSame(0, Entry::withoutGlobalScopes()->whereNull('deleted_at')->count());
        $this->assertSame(1, Entry::withoutGlobalScopes()->whereNotNull('deleted_at')->count(), 'cancelamento é lógico (fica na auditoria)');
    }

    public function test_pagar_conta_em_aberto(): void
    {
        [$a, $u, [$ca]] = $this->makeClient('profissional');
        $this->authorize($a, defaultCompany: $ca->id);
        $this->actingAs($u)->postJson('/app/api/lancamentos', ['empresa' => $ca->id, 'tipo' => 'despesa', 'desc' => 'Internet', 'valor' => 120, 'data' => now()->toDateString()])->assertCreated();
        $this->send('paguei internet');
        $this->assertTrue(Entry::withoutGlobalScopes()->first()->paid);
        $this->assertSame(1, Entry::withoutGlobalScopes()->count());
    }

    public function test_mensagem_reentregue_pela_meta_nao_duplica(): void
    {
        [$a, , [$ca]] = $this->makeClient('profissional');
        $this->authorize($a, defaultCompany: $ca->id);
        $this->send('luz 230 vence 15', wamid: 'wamid.X1');
        $this->send('luz 230 vence 15', wamid: 'wamid.X1');
        $this->assertSame(1, Entry::withoutGlobalScopes()->count());
        $this->assertSame(1, WhatsappMessage::query()->where('direction', 'in')->count());
    }

    public function test_bloqueios_por_plano_e_suspensao(): void
    {
        [$basico] = $this->makeClient('basico');
        $this->authorize($basico, '5511911111111');
        $this->send('luz 100', '5511911111111');
        $this->assertStringContainsString('não inclui', $this->lastReply());

        [$a, , [$ca]] = $this->makeClient('profissional');
        $this->authorize($a, defaultCompany: $ca->id);
        app(BillingService::class)->suspend($a, 'inadimplencia', null);
        $this->send('luz 100');
        $this->assertStringContainsString('suspensa', $this->lastReply());
        $this->send('quanto tenho para pagar hoje?');
        $this->assertStringNotContainsString('suspensa', $this->lastReply(), 'consulta continua liberada');
        $this->assertSame(0, Entry::withoutGlobalScopes()->count());
    }

    public function test_nono_digito(): void
    {
        $this->assertSame(WhatsappContact::hashPhone('5511987654321'), WhatsappContact::hashPhone('551187654321'));
        $this->assertSame(WhatsappContact::hashPhone('(11) 98765-4321'), WhatsappContact::hashPhone('5511987654321'));
    }

    public function test_caixa_lancar_rapido_do_painel_usa_o_mesmo_assistente(): void
    {
        [$a, $u, [$ca]] = $this->makeClient('basico');
        $this->actingAs($u)->postJson('/app/api/mensagem', ['texto' => 'aluguel 1.100 dia 5 fixa', 'empresa' => $ca->id, 'mes' => now()->format('Y-m')])
            ->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(12, Entry::withoutGlobalScopes()->count());
    }
}
