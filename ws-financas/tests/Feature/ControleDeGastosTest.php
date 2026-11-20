<?php

namespace Tests\Feature;

use App\Core\Audit\AuditLogger;
use App\Core\Support\Money;
use App\Models\AuditLog;
use App\Models\Budget;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Entry;
use App\Models\ImportBatch;
use App\Models\Membership;
use App\Models\Plan;
use App\Models\PlanChangeRequest;
use App\Models\ReportPreference;
use App\Models\ReportRun;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsappContact;
use App\Models\WhatsappMessage;
use App\Services\AccountService;
use App\Services\Assistant\AiInterpreter;
use App\Services\FinanceService;
use App\Services\Reports\PeriodReport;
use App\Services\Reports\ReportDispatcher;
use App\Services\Update\UpdateService;
use App\Services\WhatsApp\WhatsAppClient;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use ZipArchive;

/** IA simulada só no envio HTTP (para a reescrita opcional das recomendações). */
class FakeReportAi extends AiInterpreter
{
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

        return $next === null ? null : ['text' => $next, 'input_tokens' => 100, 'output_tokens' => 40, 'stop_reason' => 'end_turn'];
    }
}

/**
 * 1.1.7 — controle de gastos por entidade: lançamentos pelo WhatsApp, empresa/centro de custo, funcionários e aprovação,
 * relatórios automáticos por entidade, recomendações, metas, planos por faixa, importação de extrato e isolamento.
 */
class ControleDeGastosTest extends TestCase
{
    private const ADMIN = '5511987654321';

    private const EMPLOYEE = '5511912345678';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp.app_secret' => 'segredo-de-teste', 'mail.default' => 'array']);
        $this->travelTo(Carbon::parse('2026-11-18 10:00:00'));   // quarta-feira
    }

    // ------------------------------------------------------------ apoio

    private function contact($account, string $phone, array $extra = []): WhatsappContact
    {
        return $this->inAccount($account, fn () => WhatsappContact::query()->create(['label' => $extra['label'] ?? 'Dono', 'phone' => $phone,
            'phone_hash' => WhatsappContact::hashPhone($phone), 'phone_last4' => substr($phone, -4)] + $extra + ['can_write' => true, 'active' => true, 'verified_at' => now()]));
    }

    private function send(string $text, string $from = self::ADMIN)
    {
        $m = ['id' => 'wamid.'.uniqid('', true), 'from' => $from, 'type' => 'text', 'text' => ['body' => $text]];
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => ['messages' => [$m]]]]]]]);

        return $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'segredo-de-teste')], $body);
    }

    private function reply(): string
    {
        return end(WhatsAppClient::$sent)['body'] ?? '';
    }

    private function effective(?string $companyId = null)
    {
        return Entry::withoutGlobalScopes()->whereNull('deleted_at')->whereNull('approval_status')->when($companyId, fn ($q) => $q->where('company_id', $companyId))->orderBy('created_at')->get();
    }

    private function pending()
    {
        return Entry::withoutGlobalScopes()->whereNull('deleted_at')->where('approval_status', 'pendente')->get();
    }

    private function center($account, Company $company, string $name): CostCenter
    {
        return $this->inAccount($account, fn () => CostCenter::query()->create(['company_id' => $company->id, 'name' => $name, 'active' => true]));
    }

    private function pf($account, string $name = 'Fulano (CPF)'): Company
    {
        return $this->inAccount($account, fn () => app(AccountService::class)->createCompany(['name' => $name, 'person_type' => 'pf'], false));
    }

    private function launch($account, Company $c, string $tipo, string $desc, int $cents, string $date, ?string $cat = null): void
    {
        $this->inAccount($account, fn () => app(FinanceService::class)->create($c, ['tipo' => $tipo, 'desc' => $desc, 'valor_cents' => $cents, 'data' => $date, 'pago' => true, 'cat' => $cat]));
    }

    private function mails(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()->all();
    }

    // ============================================================ WhatsApp: lançamentos simples

    public function test_mensagens_simples_do_dia_a_dia_viram_lancamentos(): void
    {
        [$a] = $this->makeClient('empresarial');
        $this->contact($a, self::ADMIN);
        $cases = [
            ['100 almoço', 'despesa', 10000, 'Refeições', true],
            ['250 combustível', 'despesa', 25000, 'Combustível', true],
            ['1500 aluguel', 'despesa', 150000, null, true],
            ['Recebi 3500 de um cliente', 'receita', 350000, null, true],
            ['Paguei 180 de energia', 'despesa', 18000, 'Contas da casa', true],
        ];
        foreach ($cases as [$msg, $type, $cents, $cat, $paid]) {
            $this->send($msg)->assertOk();
            $e = $this->effective()->last();
            $this->assertSame($type, $e->type, $msg);
            $this->assertSame($cents, $e->amount_cents, $msg);
            $this->assertSame($paid, $e->paid, $msg);
            $this->assertSame('whatsapp', $e->source, $msg);
            if ($cat) {
                $this->assertSame($cat, $e->category, $msg);
            }
            $this->assertStringContainsString('registrada com sucesso', $this->reply(), $msg);
        }
        $this->assertCount(5, $this->effective());
    }

    // ============================================================ CPF + várias empresas, centro de custo

    public function test_cpf_e_varias_empresas_pergunta_a_entidade_e_o_centro_de_custo_sem_misturar(): void
    {
        [$a, $u, [$loja, $filial]] = $this->makeClient('empresarial', ['Loja Centro', 'Filial Bairro']);
        $cpf = $this->pf($a);
        $this->center($a, $loja, 'Veículos');
        $this->center($a, $loja, 'Escritório');
        $this->contact($a, self::ADMIN, ['user_id' => $u->id]);

        $this->send('100 almoço');
        $ask = $this->reply();
        $this->assertStringContainsString('Para qual empresa', $ask);
        foreach (['Filial Bairro', 'Fulano (CPF)', 'Loja Centro'] as $name) {
            $this->assertStringContainsString($name, $ask);
        }
        $this->assertCount(0, $this->effective());

        $this->send('3');   // Loja Centro (ordem alfabética)
        $this->assertStringContainsString('Qual o centro de custo?', $this->reply());
        $this->assertStringContainsString('0 - Sem centro de custo', $this->reply());
        $this->send('1');   // Escritório
        $e = $this->effective()->sole();
        $this->assertSame($loja->id, $e->company_id);
        $this->assertSame('Escritório', CostCenter::withoutGlobalScopes()->find($e->cost_center_id)->name);
        $this->assertStringContainsString('🏷️ Centro de custo: Escritório', $this->reply());
        $this->assertStringContainsString('🏢 Empresa: Loja Centro', $this->reply());

        // centro de custo citado na mensagem: identificado sem perguntar
        $this->send('250 combustível veículos empresa A');
        $e2 = $this->effective()->last();
        $this->assertSame($loja->id, $e2->company_id);
        $this->assertSame('Veículos', CostCenter::withoutGlobalScopes()->find($e2->cost_center_id)->name);

        // CPF: sem centros de custo, lança direto na pessoa física (nada vai para as empresas)
        $this->send('80 farmácia');
        $this->send('2');
        $e3 = $this->effective()->last();
        $this->assertSame($cpf->id, $e3->company_id);
        $this->assertNull($e3->cost_center_id);
        $this->assertSame(0, $this->effective($filial->id)->count());

        // relatório do CPF não inclui gastos das empresas (e vice-versa)
        $facts = $this->inAccount($a, fn () => app(PeriodReport::class)->facts($cpf, 'mensal', CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30')));
        $this->assertSame(8000, $facts['despesas']);
    }

    public function test_categorias_padrao_pf_pj_e_categorias_proprias(): void
    {
        [$a, $u, [$loja]] = $this->makeClient('empresarial', ['Loja Centro']);
        $cpf = $this->pf($a);
        $this->inAccount($a, function () use ($loja, $cpf) {
            $this->assertContains('Fornecedores', $loja->categories('despesa'));
            $this->assertNotContains('Fornecedores', $cpf->categories('despesa'));
            $this->assertContains('Moradia', $cpf->categories('despesa'));
            $this->assertSame('Outros', last($loja->categories('despesa')));
        });
        $this->actingAs($u)->post('/app/empresas/'.$loja->id.'/categorias', ['name' => 'Embalagens', 'type' => 'despesa'])->assertSessionHas('ok');
        $this->actingAs($u)->postJson('/app/api/lancamentos', ['empresa' => $loja->id, 'tipo' => 'despesa', 'desc' => 'Caixas', 'valor' => '50,00', 'cat' => 'Embalagens', 'data' => '2026-11-18'])->assertCreated();
        $this->assertSame('Embalagens', $this->effective()->last()->category);
        // categoria própria de uma empresa não vale em outra entidade
        $this->actingAs($u)->postJson('/app/api/lancamentos', ['empresa' => $cpf->id, 'tipo' => 'despesa', 'desc' => 'Caixas', 'valor' => '50,00', 'cat' => 'Embalagens', 'data' => '2026-11-18'])->assertCreated();
        $this->assertNotSame('Embalagens', $this->effective()->last()->category);
    }

    // ============================================================ funcionários e aprovação (WhatsApp)

    public function test_funcionario_pelo_whatsapp_com_empresas_centros_limite_e_aprovacao(): void
    {
        [$a, $u, [$loja, $filial]] = $this->makeClient('empresarial', ['Loja Centro', 'Filial Bairro']);
        $obra = $this->center($a, $filial, 'Obra 12');
        $this->center($a, $filial, 'Escritório');
        $this->contact($a, self::ADMIN, ['user_id' => $u->id]);
        $this->contact($a, self::EMPLOYEE, ['label' => 'Carlos', 'requires_approval' => true, 'approval_limit_cents' => 20000,
            'company_ids' => [$filial->id], 'cost_center_ids' => [$obra->id]]);

        // abaixo do limite: efetiva na hora, na única empresa e no único centro permitidos
        $this->send('100 almoço', self::EMPLOYEE);
        $e = $this->effective()->sole();
        $this->assertSame($filial->id, $e->company_id);
        $this->assertSame($obra->id, $e->cost_center_id);
        $this->assertSame('Carlos (final 5678)', $e->responsible);

        // acima do limite: pendente, fora dos totais
        $this->send('Paguei 500 de material de construção', self::EMPLOYEE);
        $this->assertStringContainsString('⏳ Despesa enviada para aprovação!', $this->reply());
        $this->assertStringContainsString('depois de aprovada', $this->reply());
        $p = $this->pending()->sole();
        $this->assertSame(50000, $p->amount_cents);
        $this->assertCount(1, $this->effective());
        $this->actingAs($u)->getJson('/app/api/mes?empresa='.$filial->id.'&mes=2026-11')->assertOk()->assertJsonMissing(['id' => $p->id]);
        $this->assertTrue(AuditLog::query()->where('action', 'lancamento.detalhes')->get()->contains(fn ($l) => ($l->data['pendente_aprovacao'] ?? false) === true));
        // o registro "lancamento.criar" mantém o formato histórico de 6 campos (verificável também pela 1.1.6 após uma reversão)
        foreach (AuditLog::query()->where('action', 'lancamento.criar')->get() as $l) {
            $this->assertSame(['desc', 'modo', 'origem', 'repetir', 'tipo', 'valor'], collect(array_keys($l->data))->sort()->values()->all());
        }

        // funcionário não aprova; não vê a empresa não autorizada
        $this->send('pendentes', self::EMPLOYEE);
        $this->assertStringContainsString('Somente administrador ou gestor', $this->reply());
        $this->send('quanto gastei este mês?', self::EMPLOYEE);
        $this->assertStringNotContainsString('Loja Centro', $this->reply());

        // administrador aprova pelo WhatsApp
        $this->send('pendentes');
        $this->assertStringContainsString('Material', $this->reply());
        $this->assertStringContainsString('por Carlos', $this->reply());
        $this->send('aprovar 1');
        $this->assertStringContainsString('✅ Aprovada', $this->reply());
        $this->assertCount(0, $this->pending());
        $this->assertCount(2, $this->effective($filial->id));
        $this->assertTrue(AuditLog::query()->where('action', 'lancamento.aprovar')->exists());
        $this->assertSame(0, $this->effective($loja->id)->count());
        $this->assertTrue(AuditLogger::verify()['ok'], 'auditoria íntegra com os campos novos (centro, responsável, pendência)');
    }

    // ============================================================ funcionários e aprovação (painel)

    public function test_usuario_do_painel_com_aprovacao_gestor_aprova_e_rejeita(): void
    {
        [$a, $admin, [$loja]] = $this->makeClient('empresarial', ['Loja Centro']);
        $this->actingAs($admin)->post('/app/usuarios', ['name' => 'Fin', 'email' => 'fin@t.local', 'role' => 'financeiro', 'all_companies' => '1', 'password' => 'Senha@Forte123',
            'requires_approval' => '1', 'approval_limit' => ''])->assertSessionHas('ok');
        $this->actingAs($admin)->post('/app/usuarios', ['name' => 'Gestora', 'email' => 'ges@t.local', 'role' => 'gestor', 'all_companies' => '1', 'password' => 'Senha@Forte123'])->assertSessionHas('ok');
        $fin = User::query()->where('email', 'fin@t.local')->sole();
        $ges = User::query()->where('email', 'ges@t.local')->sole();
        $this->assertTrue(Membership::withoutGlobalScopes()->where('user_id', $fin->id)->value('requires_approval'));

        $r = $this->actingAs($fin)->postJson('/app/api/lancamentos', ['empresa' => $loja->id, 'tipo' => 'despesa', 'desc' => 'Notebook', 'valor' => '3.000,00', 'data' => '2026-11-18', 'pago' => true])
            ->assertCreated()->assertJson(['pendente' => true]);
        $first = $r->json('itens.0.id');
        $this->actingAs($fin)->postJson('/app/api/lancamentos', ['empresa' => $loja->id, 'tipo' => 'despesa', 'desc' => 'Cadeira', 'valor' => '800,00', 'data' => '2026-11-18'])->assertCreated();
        // receita não passa por aprovação
        $this->actingAs($fin)->postJson('/app/api/lancamentos', ['empresa' => $loja->id, 'tipo' => 'receita', 'desc' => 'Venda', 'valor' => '100,00', 'data' => '2026-11-18'])->assertJson(['pendente' => false]);
        $this->assertCount(2, $this->pending());
        $this->assertSame('Fin', $this->pending()->first()->responsible);

        $this->actingAs($fin)->get('/app/aprovacoes')->assertForbidden();
        $this->actingAs($ges)->get('/app/aprovacoes')->assertOk()->assertSee('Notebook')->assertSee('Cadeira');
        $this->actingAs($ges)->get('/app/resumo')->assertOk()->assertSee('Aguardando aprovação');

        $this->actingAs($ges)->post('/app/aprovacoes/'.$first, ['decisao' => 'aprovar'])->assertSessionHas('ok');
        $cadeira = $this->pending()->sole();
        $this->actingAs($admin)->post('/app/aprovacoes/'.$cadeira->id, ['decisao' => 'rejeitar'])->assertSessionHas('ok');
        $this->assertCount(0, $this->pending());
        $rej = Entry::withoutGlobalScopes()->withTrashed()->find($cadeira->id);
        $this->assertSame('rejeitada', $rej->approval_status);
        $this->assertNotNull($rej->deleted_at);
        $this->assertSame(['Notebook', 'Venda'], $this->effective()->pluck('description')->sort()->values()->all());
        $this->assertTrue(AuditLog::query()->where('action', 'lancamento.rejeitar')->exists());
        // decisão repetida não altera nada
        $this->actingAs($admin)->post('/app/aprovacoes/'.$cadeira->id, ['decisao' => 'aprovar'])->assertSessionHas('error');
        $this->assertTrue(AuditLogger::verify()['ok']);
    }

    // ============================================================ metas

    public function test_metas_mensal_e_semanal_avisam_no_lancamento(): void
    {
        [$a, $u, [$loja]] = $this->makeClient('empresarial', ['Loja Centro']);
        $this->contact($a, self::ADMIN);
        $this->actingAs($u)->post('/app/empresas/'.$loja->id.'/metas', ['mes' => '500,00', 'semana' => '', 'cat' => 'Combustível', 'cat_period' => 'semana', 'cat_valor' => '100,00'])
            ->assertSessionHas('ok');
        $this->assertSame(2, Budget::withoutGlobalScopes()->count());

        $this->send('450 almoço');
        $this->assertStringContainsString('⚠️ Meta mensal: 90% usada', $this->reply());
        $this->send('120 combustível');
        $this->assertStringContainsString('🚨 Meta mensal estourada', $this->reply());
        $this->assertStringContainsString('🚨 Meta semanal de Combustível estourada', $this->reply());
        $this->actingAs($u)->get('/app/resumo?empresa='.$loja->id)->assertOk()->assertSee('Metas de gasto')->assertSee('Meta mensal');
    }

    // ============================================================ relatórios automáticos e recomendações

    private function reportScenario(): array
    {
        [$a, $u, [$loja]] = $this->makeClient('empresarial', ['Loja Centro']);
        $cpf = $this->pf($a);
        $this->launch($a, $loja, 'despesa', 'Posto', 10000, '2026-11-10', 'Combustível');   // semana anterior à do relatório
        $this->launch($a, $loja, 'despesa', 'Posto', 30000, '2026-11-17', 'Combustível');
        $this->launch($a, $loja, 'despesa', 'Almoço equipe', 20000, '2026-11-18', 'Refeições');
        $this->launch($a, $loja, 'receita', 'Vendas', 100000, '2026-11-19', 'Vendas');
        $this->launch($a, $cpf, 'despesa', 'Aluguel', 150000, '2026-11-20', 'Moradia');
        $this->inAccount($a, fn () => ReportPreference::query()->create(['weekly' => true, 'monthly' => true, 'via_whatsapp' => true, 'via_email' => true]));
        $this->contact($a, self::ADMIN, ['user_id' => $u->id]);

        return [$a, $u, $loja, $cpf];
    }

    public function test_relatorio_semanal_por_entidade_com_comparacao_e_recomendacoes_sem_duplicar(): void
    {
        [$a, $u, $loja, $cpf] = $this->reportScenario();
        $this->travelTo(Carbon::parse('2026-11-23 07:40:00'));   // segunda-feira
        WhatsAppClient::$sent = [];

        $r = $this->ctx()->runAsSystem(fn () => app(ReportDispatcher::class)->run('semanal'));
        $this->assertSame(['enviados' => 2, 'ignorados' => 2, 'falhas' => 0, 'pendentes' => 0], $r);   // e-mail de cada entidade; WhatsApp fora da janela e sem modelo
        $mails = $this->mails();
        $this->assertCount(2, $mails);
        $byName = collect($mails)->mapWithKeys(fn ($m) => [$m->getOriginalMessage()->getSubject() => $m->getOriginalMessage()->getTextBody()]);
        $loja = $byName->first(fn ($b, $s) => str_contains($s, 'Loja Centro'));
        $this->assertNotNull($loja);
        foreach (['Receitas: '.Money::brl(100000), 'Despesas: '.Money::brl(50000), 'Saldo: '.Money::brl(50000), 'Período anterior: despesas '.Money::brl(10000).' (+400%)',
            'Combustível: '.Money::brl(30000).' (60%)', 'Os gastos subiram 400%', 'Maior aumento: Combustível, +'.Money::brl(20000).' (+200%)',
            'Combustível concentra 60%', 'reduzir 20% em Combustível economizaria cerca de '.Money::brl(6000).' por semana (estimativa'] as $line) {
            $this->assertStringContainsString($line, $loja);
        }
        $this->assertStringNotContainsString('Aluguel', $loja);   // nada do CPF no relatório da empresa
        $cpfMail = $byName->first(fn ($b, $s) => str_contains($s, 'Fulano (CPF)'));
        $this->assertStringContainsString('Despesas: '.Money::brl(150000), $cpfMail);
        $this->assertStringContainsString('relatório semanal', mb_strtolower(collect($mails)->first()->getOriginalMessage()->getSubject()));
        $this->assertSame([], WhatsAppClient::$sent);
        $this->assertSame(2, ReportRun::withoutGlobalScopes()->where('status', 'ignorado')->where('detail', 'like', '%sem modelo aprovado%')->count());

        // de novo (cron repetido): nada é reenviado
        $r2 = $this->ctx()->runAsSystem(fn () => app(ReportDispatcher::class)->run('semanal'));
        $this->assertSame(0, $r2['enviados'] + $r2['falhas'] + $r2['ignorados']);
        $this->assertCount(2, $this->mails());
        $this->assertSame(4, ReportRun::withoutGlobalScopes()->count());

        // pelo WhatsApp sob demanda: o mesmo relatório, por entidade
        $this->send('relatório empresa A');
        $this->assertStringContainsString('Relatório semanal — Loja Centro', $this->reply());
        $this->assertStringContainsString('Os gastos subiram 400%', $this->reply());
        $this->send('relatório');
        $this->assertStringContainsString('Resumo por entidade', $this->reply());
    }

    public function test_relatorio_no_whatsapp_janela_de_24h_modelo_aprovado_lotes_e_opt_out(): void
    {
        [$a, $u, $loja, $cpf] = $this->reportScenario();
        $this->inAccount($a, fn () => ReportPreference::query()->first()->update(['via_email' => false, 'company_ids' => [$loja->id]]));

        // modelo aprovado configurado: fora da janela de 24 h vai o modelo (com os números da entidade)
        config(['services.whatsapp.report_template' => 'relatorio_pronto']);
        $this->travelTo(Carbon::parse('2026-11-23 07:40:00'));
        WhatsAppClient::$sent = [];
        $this->ctx()->runAsSystem(fn () => app(ReportDispatcher::class)->run('semanal'));
        $this->assertCount(1, WhatsAppClient::$sent);
        $this->assertSame('template', WhatsAppClient::$sent[0]['type']);
        $this->assertStringContainsString('Loja Centro', WhatsAppClient::$sent[0]['body']);
        $this->assertStringContainsString(Money::brl(50000), WhatsAppClient::$sent[0]['body']);

        // conversa aberta (mensagem nas últimas 24 h): mensal vai como texto completo, no dia 1º
        $this->travelTo(Carbon::parse('2026-12-01 07:00:00'));
        $this->send('oi');
        WhatsAppClient::$sent = [];
        $this->travelTo(Carbon::parse('2026-12-01 07:40:00'));
        $this->ctx()->runAsSystem(fn () => app(ReportDispatcher::class)->run('mensal'));
        $this->assertCount(1, WhatsAppClient::$sent);
        $this->assertSame('text', WhatsAppClient::$sent[0]['type']);
        $this->assertStringContainsString('Relatório mensal — Loja Centro', WhatsAppClient::$sent[0]['body']);
        $this->assertStringContainsString('novembro', mb_strtolower(WhatsAppClient::$sent[0]['body']));

        // lotes: limite por execução, o restante sai na seguinte
        $this->inAccount($a, fn () => ReportPreference::query()->first()->update(['via_email' => true, 'company_ids' => null]));
        $this->travelTo(Carbon::parse('2026-11-30 07:40:00'));
        $r = $this->ctx()->runAsSystem(fn () => app(ReportDispatcher::class)->run('semanal', null, 1));
        $this->assertSame(1, $r['enviados'] + $r['ignorados']);
        $this->assertSame(3, $r['pendentes']);
        $r = $this->ctx()->runAsSystem(fn () => app(ReportDispatcher::class)->run('semanal'));
        $this->assertSame(3, $r['enviados'] + $r['ignorados']);

        // envio que falhou é tentado de novo na execução seguinte; o que foi enviado não se repete
        $this->travelTo(Carbon::parse('2026-12-14 07:40:00'));
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 2]);
        $r = $this->ctx()->runAsSystem(fn () => app(ReportDispatcher::class)->run('semanal'));
        $this->assertSame(2, $r['falhas']);
        config(['mail.default' => 'array']);
        $r = $this->ctx()->runAsSystem(fn () => app(ReportDispatcher::class)->run('semanal'));
        $this->assertSame(2, $r['enviados']);
        $r = $this->ctx()->runAsSystem(fn () => app(ReportDispatcher::class)->run('semanal'));
        $this->assertSame(0, $r['enviados'] + $r['falhas']);

        // desativado (opt-out): nada é enviado
        $this->inAccount($a, fn () => ReportPreference::query()->first()->update(['weekly' => false, 'monthly' => false]));
        $this->travelTo(Carbon::parse('2026-12-07 07:40:00'));
        $before = ReportRun::withoutGlobalScopes()->count();
        $this->ctx()->runAsSystem(fn () => app(ReportDispatcher::class)->run('semanal'));
        $this->assertSame($before, ReportRun::withoutGlobalScopes()->count());
    }

    public function test_recomendacoes_usam_so_numeros_reais_e_ia_com_numero_inventado_e_descartada(): void
    {
        [$a, $u, $loja] = $this->reportScenario();
        $ai = new FakeReportAi;
        $this->app->instance(AiInterpreter::class, $ai);
        config(['wsf.reports.ai_text' => true]);
        $rep = app(PeriodReport::class);
        [$from, $to] = PeriodReport::lastPeriod('semanal', CarbonImmutable::parse('2026-11-23'));
        $facts = $this->inAccount($a, fn () => $rep->facts($loja, 'semanal', $from, $to));
        $rules = $rep->insights($facts);

        // cada número das recomendações existe nos dados do período
        $allowed = PeriodReport::numbers(json_encode($facts).' '.implode(' ', array_map(fn ($c) => Money::brl($c), [10000, 20000, 30000, 50000, 6000, 100000])).' 400 200 60 20');
        foreach (PeriodReport::numbers(implode(' ', $rules)) as $n) {
            $this->assertContains($n, $allowed, 'número sem origem nos dados: '.$n);
        }

        $ai->queue = ['📈 Seus gastos subiram 400%. 💡 Dá para economizar '.Money::brl(99999).' garantidos!'];
        $this->assertSame($rules, $this->inAccount($a, fn () => $rep->polish($a->id, $facts, $rules)));   // número inventado → mantém as regras
        $ai->queue = ["📈 Atenção: os gastos subiram 400% na comparação com a semana anterior.\n💡 Cortar 20% em Combustível pouparia cerca de ".Money::brl(6000).' por semana.'];
        $polished = $this->inAccount($a, fn () => $rep->polish($a->id, $facts, $rules));
        $this->assertNotSame($rules, $polished);
        $this->assertStringContainsString('Atenção', $polished[0]);
    }

    public function test_preferencias_escolhidas_no_cadastro_e_alteraveis_depois(): void
    {
        $this->post('/cadastro', ['person_type' => 'pf', 'name' => 'Maria', 'admin_name' => 'Maria', 'email' => 'maria@t.local', 'password' => 'Senha@Forte123',
            'password_confirmation' => 'Senha@Forte123', 'plan' => 'profissional', 'cycle' => 'monthly', 'accept' => '1',
            'report_weekly' => '1', 'report_monthly' => '0', 'report_whatsapp' => '0', 'report_email' => '1'])->assertRedirect('/app');
        $pref = ReportPreference::withoutGlobalScopes()->sole();
        $this->assertTrue($pref->weekly);
        $this->assertFalse($pref->monthly);
        $this->assertTrue($pref->via_email);
        $this->assertFalse($pref->via_whatsapp);

        $this->get('/app/relatorios-automaticos')->assertOk()->assertSee('Relatórios automáticos')->assertSee('Exemplo');
        $this->put('/app/relatorios-automaticos', ['weekly' => '0', 'monthly' => '1', 'via_whatsapp' => '1', 'via_email' => '0', 'all_companies' => '1'])->assertSessionHas('ok');
        $pref->refresh();
        $this->assertFalse($pref->weekly);
        $this->assertTrue($pref->monthly);
        $this->assertTrue($pref->via_whatsapp);
        $this->get('/app/resumo')->assertOk()->assertSee('Recomendações');
    }

    // ============================================================ planos por faixa

    public function test_planos_por_faixa_cpf_nao_conta_mudanca_so_com_autorizacao_e_plano_personalizado(): void
    {
        foreach (['pessoal', 'ate3', 'ate8', 'ate15', 'ate25', 'ate50', 'personalizado'] as $code) {
            $p = Plan::query()->where('code', $code)->sole();
            $this->assertFalse($p->active, $code.' deve vir inativo');
            $this->assertSame(0, (int) $p->price_monthly_cents);
        }
        Plan::query()->whereIn('code', ['pessoal', 'ate3', 'ate8'])->update(['active' => true]);
        Plan::query()->where('code', 'ate3')->update(['price_monthly_cents' => 9900]);
        Plan::query()->where('code', 'ate8')->update(['price_monthly_cents' => 19900]);
        Plan::query()->whereIn('code', ['basico', 'profissional', 'empresarial'])->update(['active' => false]);   // proprietário passou a vender só as faixas

        [$a, $u] = $this->makeClient('ate3', ['Empresa 1']);
        $this->actingAs($u)->post('/app/empresas', ['name' => 'Meu CPF', 'person_type' => 'pf'])->assertSessionHas('ok');   // CPF do titular não conta
        $this->actingAs($u)->post('/app/empresas', ['name' => 'Empresa 2', 'person_type' => 'pj'])->assertSessionHas('ok');
        $this->actingAs($u)->post('/app/empresas', ['name' => 'Empresa 3', 'person_type' => 'pj'])->assertSessionHas('ok');
        $r = $this->actingAs($u)->post('/app/empresas', ['name' => 'Empresa 4', 'person_type' => 'pj']);
        $err = session('error');
        $this->assertStringContainsString('De 4 a 8 empresas', $err);
        $this->assertStringContainsString('Nenhuma cobrança muda sem a sua autorização', $err);
        $this->assertSame(4, Company::withoutGlobalScopes()->where('account_id', $a->id)->count());

        // assinatura e troca: sem autorização expressa nada muda
        $this->actingAs($u)->post('/app/assinatura', ['plan' => 'ate3', 'cycle' => 'monthly', 'document' => '11144477735'])->assertSessionHas('ok');
        $sub = Subscription::query()->where('account_id', $a->id)->sole();
        $this->assertSame('ate3', $sub->plan->code);
        $this->actingAs($u)->post('/app/assinatura', ['plan' => 'ate8', 'cycle' => 'monthly'])->assertSessionHas('error');
        $this->assertSame('ate3', $sub->fresh()->plan->code);
        $this->actingAs($u)->post('/app/assinatura', ['plan' => 'ate8', 'cycle' => 'monthly', 'consent' => '1'])->assertSessionHas('ok');
        $this->assertSame('ate8', $sub->fresh()->plan->code);
        $this->assertSame(19900, $sub->fresh()->price_cents);
        $this->actingAs($u)->post('/app/empresas', ['name' => 'Empresa 4', 'person_type' => 'pj'])->assertSessionHas('ok');

        // personalizado (acima de 50): pedido vai ao proprietário; nada é cobrado
        $this->actingAs($u)->get('/app/assinatura')->assertOk()->assertSee('plano personalizado');
        $this->actingAs($u)->post('/app/assinatura/pedido', ['companies_wanted' => 60, 'message' => 'Grupo com 60 lojas'])->assertSessionHas('ok');
        $req = PlanChangeRequest::withoutGlobalScopes()->sole();
        $this->assertSame('pendente', $req->status);
        $owner = User::query()->create(['name' => 'Dono', 'email' => 'dono@t.local', 'password' => 'Senha@Forte123', 'is_platform_admin' => true]);
        $this->actingAs($owner)->get('/admin/planos')->assertOk()->assertSee('Pedidos de mudança de plano')->assertSee('Grupo com 60 lojas');
        $this->actingAs($owner)->post('/admin/planos/pedidos/'.$req->id, ['status' => 'atendido'])->assertSessionHas('ok');
        $this->assertSame('atendido', $req->fresh()->status);
        $this->assertSame('ate8', $sub->fresh()->plan->code);

        // plano pessoal: só CPF
        $this->post('/sair');
        $this->post('/cadastro', ['person_type' => 'pj', 'name' => 'X', 'admin_name' => 'X', 'email' => 'x@t.local', 'password' => 'Senha@Forte123', 'password_confirmation' => 'Senha@Forte123',
            'plan' => 'pessoal', 'cycle' => 'monthly', 'accept' => '1'])->assertSessionHasErrors('plan');
        $this->post('/cadastro', ['person_type' => 'pf', 'name' => 'Ana', 'admin_name' => 'Ana', 'email' => 'ana@t.local', 'password' => 'Senha@Forte123', 'password_confirmation' => 'Senha@Forte123',
            'plan' => 'pessoal', 'cycle' => 'monthly', 'accept' => '1'])->assertRedirect('/app');
        $this->post('/app/empresas', ['name' => 'Firma', 'person_type' => 'pj'])->assertSessionHas('error');
        $this->assertStringContainsString('somente o CPF do titular', session('error'));
    }

    // ============================================================ importação de extrato

    private function upload($u, Company $c, string $name, string $content)
    {
        $r = $this->actingAs($u)->post('/app/importar/extrato', ['empresa' => $c->id, 'extrato' => UploadedFile::fake()->createWithContent($name, $content)]);
        $r->assertRedirect();

        return ImportBatch::withoutGlobalScopes()->latest('created_at')->latest('id')->first();
    }

    private function xlsx(array $rows): string
    {
        $file = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::OVERWRITE);
        $strings = [];
        $sheet = '';
        foreach ($rows as $ri => $row) {
            $sheet .= '<row r="'.($ri + 1).'">';
            foreach ($row as $ci => $v) {
                $ref = chr(65 + $ci).($ri + 1);
                if (is_int($v) || is_float($v)) {
                    $sheet .= '<c r="'.$ref.'"><v>'.$v.'</v></c>';
                } else {
                    $strings[] = htmlspecialchars($v, ENT_XML1);
                    $sheet .= '<c r="'.$ref.'" t="s"><v>'.(count($strings) - 1).'</v></c>';
                }
            }
            $sheet .= '</row>';
        }
        $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook '.$ns.' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Extrato" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst '.$ns.'>'.implode('', array_map(fn ($s) => '<si><t>'.$s.'</t></si>', $strings)).'</sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet '.$ns.'><sheetData>'.$sheet.'</sheetData></worksheet>');
        $zip->close();
        $content = (string) file_get_contents($file);
        unlink($file);

        return $content;
    }

    private function pdf(array $lines): string
    {
        $ops = "BT /F1 10 Tf 50 750 Td\n";
        foreach ($lines as $l) {
            $ops .= '('.addcslashes($l, '()\\').") Tj 0 -14 Td\n";
        }
        $ops .= 'ET';
        $data = gzcompress($ops);

        return "%PDF-1.4\n1 0 obj\n<< /Length ".strlen($data)." /Filter /FlateDecode >>\nstream\n".$data."\nendstream\nendobj\ntrailer\n<< >>\n%%EOF\n";
    }

    public function test_importacao_ofx_csv_excel_e_pdf_com_previa_confirmacao_e_sem_duplicar(): void
    {
        [$a, $u, [$loja]] = $this->makeClient('empresarial', ['Loja Centro']);
        // lançamento que já veio pelo WhatsApp: a mesma transação no CSV é marcada como parecida
        $this->launch($a, $loja, 'despesa', 'Posto Shell', 25000, '2026-11-10', 'Combustível');

        $ofx = "OFXHEADER:100\nDATA:OFXSGML\n\n<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><BANKTRANLIST>\n"
            ."<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20261110120000[-3:BRT]<TRNAMT>-123.45<FITID>A1<MEMO>MERCADO CENTRAL\n"
            ."<STMTTRN><TRNTYPE>CREDIT<DTPOSTED>20261111<TRNAMT>1500.00<FITID>A2<MEMO>PIX RECEBIDO CLIENTE\n"
            ."</BANKTRANLIST></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>";
        $b = $this->upload($u, $loja, 'extrato.ofx', $ofx);
        $this->assertSame('ofx', $b->format);
        $this->assertSame('previa', $b->status);
        $this->assertCount(2, $b->rows);
        $this->assertSame(1, $this->effective()->count());   // prévia não lança nada
        $this->actingAs($u)->get('/app/importar/extrato/'.$b->id)->assertOk()->assertSee('MERCADO CENTRAL')->assertSee('Importar selecionadas');
        $this->actingAs($u)->post('/app/importar/extrato/'.$b->id, ['linhas' => [0, 1]])->assertSessionHas('ok');
        $imp = $this->effective()->where('source', 'import')->values();
        $this->assertCount(2, $imp);
        $this->assertSame([12345, 150000], $imp->pluck('amount_cents')->sort()->values()->all());
        $this->assertSame('despesa', $imp->firstWhere('amount_cents', 12345)->type);
        $this->assertTrue($imp->every(fn ($e) => $e->paid));

        // o mesmo extrato de novo: tudo marcado como já importado; confirmar não duplica
        $b2 = $this->upload($u, $loja, 'extrato-de-novo.ofx', $ofx);
        $this->assertSame(['importada', 'importada'], array_column($b2->rows, 'dup'));
        $this->actingAs($u)->post('/app/importar/extrato/'.$b2->id, ['linhas' => [0, 1]])->assertSessionHas('ok');
        $this->assertStringContainsString('2 já existiam', session('ok'));
        $this->assertCount(2, $this->effective()->where('source', 'import'));

        // CSV (ponto e vírgula, valores brasileiros, linha de saldo ignorada)
        $csv = "Data;Descrição;Valor\n10/11/2026;Posto Shell;-250,00\n11/11/2026;Venda balcão;800,00\n12/11/2026;Saldo do dia;1.234,00\n13/11/2026;Farmácia;-45,90\n";
        $b3 = $this->upload($u, $loja, 'extrato.csv', $csv);
        $this->assertCount(3, $b3->rows);
        $this->assertSame('parecida', $b3->rows[0]['dup']);
        $this->assertNull($b3->rows[1]['dup']);
        $this->assertSame('Combustível', $b3->rows[0]['cat']);
        $this->actingAs($u)->post('/app/importar/extrato/'.$b3->id, ['linhas' => [1, 2], 'cat' => [2 => 'Saúde']])->assertSessionHas('ok');
        $this->assertSame('Saúde', $this->effective()->firstWhere('description', 'Farmácia')->category);
        $this->assertSame(1, $this->effective()->where('description', 'Posto Shell')->count());

        // Excel (.xlsx) com crédito/débito em colunas separadas e data como número do Excel
        $serial = (int) CarbonImmutable::create(1899, 12, 30)->diffInDays(CarbonImmutable::parse('2026-11-14'));
        $xlsx = $this->xlsx([['Extrato da conta'], ['Data', 'Histórico', 'Crédito', 'Débito'], [$serial, 'Energia elétrica', '', '310,20'], ['15/11/2026', 'Transferência recebida', '2.000,00', '']]);
        $b4 = $this->upload($u, $loja, 'extrato.xlsx', $xlsx);
        $this->assertSame('xlsx', $b4->format);
        $this->assertSame([['2026-11-14', 'Energia elétrica', 31020, 'despesa'], ['2026-11-15', 'Transferência recebida', 200000, 'receita']],
            array_map(fn ($r) => [$r['data'], $r['desc'], $r['valor_cents'], $r['tipo']], $b4->rows));

        // PDF com texto
        $pdf = $this->pdf(['Extrato de conta corrente', '16/11/2026 FARMACIA POPULAR -45,90', '17/11/2026 PIX RECEBIDO JOAO 300,00 C', '17/11/2026 SALDO DO DIA 5.000,00']);
        $b5 = $this->upload($u, $loja, 'extrato.pdf', $pdf);
        $this->assertSame('pdf', $b5->format);
        $this->assertSame([['2026-11-16', 4590, 'despesa'], ['2026-11-17', 30000, 'receita']], array_map(fn ($r) => [$r['data'], $r['valor_cents'], $r['tipo']], $b5->rows));
        $this->actingAs($u)->post('/app/importar/extrato/'.$b5->id.'/cancelar')->assertSessionHas('ok');
        $this->assertSame('cancelado', $b5->fresh()->status);

        // PDF escaneado (sem texto) e arquivo desconhecido: mensagem clara, nada lançado
        $this->actingAs($u)->post('/app/importar/extrato', ['empresa' => $loja->id, 'extrato' => UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.4\n%%EOF")])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'escaneado'));
        $this->actingAs($u)->post('/app/importar/extrato', ['empresa' => $loja->id, 'extrato' => UploadedFile::fake()->createWithContent('x.csv', "a;b\n1;2\n")])
            ->assertSessionHas('error');
        $this->assertTrue(AuditLog::query()->where('action', 'importacao.extrato')->exists());
        $this->assertTrue(AuditLogger::verify()['ok']);
    }

    // ============================================================ isolamento

    public function test_isolamento_entre_clientes_e_entre_entidades(): void
    {
        [$a, $ua, [$la]] = $this->makeClient('empresarial', ['Loja A']);
        [$b, $ub, [$lb]] = $this->makeClient('empresarial', ['Loja B']);
        $cc = $this->center($a, $la, 'Veículos');
        $this->actingAs($ua)->post('/app/usuarios', ['name' => 'Fin', 'email' => 'fin2@t.local', 'role' => 'financeiro', 'all_companies' => '1', 'password' => 'Senha@Forte123', 'requires_approval' => '1'])->assertSessionHas('ok');
        $fin = User::query()->where('email', 'fin2@t.local')->sole();
        $id = $this->actingAs($fin)->postJson('/app/api/lancamentos', ['empresa' => $la->id, 'tipo' => 'despesa', 'desc' => 'Pneu', 'valor' => '900,00', 'data' => '2026-11-18'])->json('itens.0.id');

        // cliente B não vê nem decide nada do cliente A
        $this->actingAs($ub)->get('/app/aprovacoes')->assertOk()->assertDontSee('Pneu');
        $this->actingAs($ub)->post('/app/aprovacoes/'.$id, ['decisao' => 'aprovar'])->assertSessionHas('error');
        $this->assertSame(1, $this->pending()->count());
        $this->actingAs($ub)->put('/app/centros-de-custo/'.$cc->id, ['name' => 'Hack', 'active' => '1'])->assertNotFound();
        $this->actingAs($ub)->postJson('/app/api/lancamentos', ['empresa' => $lb->id, 'tipo' => 'despesa', 'desc' => 'x', 'valor' => '1,00', 'data' => '2026-11-18', 'centro' => $cc->id])
            ->assertStatus(422);
        $this->actingAs($ub)->post('/app/empresas/'.$la->id.'/metas', ['mes' => '100,00'])->assertNotFound();
        $batch = $this->upload($ua, $la, 'e.csv', "Data;Descrição;Valor\n10/11/2026;Teste;-10,00\n");
        $this->actingAs($ub)->get('/app/importar/extrato/'.$batch->id)->assertNotFound();
        $this->actingAs($ub)->get('/app/resumo?empresa='.$la->id)->assertOk()->assertDontSee('Loja A');

        // centro de custo de outra empresa do mesmo cliente é recusado
        [, , [$x, $y]] = [null, null, $this->inAccount($a, fn () => [$la, app(AccountService::class)->createCompany(['name' => 'Loja A2', 'person_type' => 'pj'], false)])];
        $this->actingAs($ua)->postJson('/app/api/lancamentos', ['empresa' => $y->id, 'tipo' => 'despesa', 'desc' => 'x', 'valor' => '1,00', 'data' => '2026-11-18', 'centro' => $cc->id])
            ->assertStatus(422);
    }

    // ============================================================ atualização 1.1.6 → 1.1.7

    public function test_migracao_aditiva_idempotente_e_reversao_bloqueada_com_pendencias(): void
    {
        $migration = require database_path('migrations/2026_11_21_000100_v117_controle_de_gastos.php');
        $migration->up();    // reaplicar não falha nem duplica

        // reversão para a 1.1.6 e reaplicação: nenhum valor da 1.1.7 se perde
        [$a, $u, [$loja]] = $this->makeClient('empresarial', ['Loja']);
        $cc = $this->center($a, $loja, 'Obra');
        $this->inAccount($a, fn () => Membership::query()->where('user_id', $u->id)->first()->update(['requires_approval' => true, 'approval_limit_cents' => 5000, 'cost_center_ids' => [$cc->id]]));
        $this->ctx()->runFor($a->id, fn () => app(FinanceService::class)->create($loja, ['tipo' => 'despesa', 'desc' => 'Com centro', 'valor_cents' => 1000, 'data' => '2026-11-18', 'centro' => $cc->id]));
        $before = [Entry::withoutGlobalScopes()->where('description', 'Com centro')->value('cost_center_id'), Plan::query()->where('code', 'pessoal')->value('kind')];
        $migration->down();
        foreach (['cost_centers', 'company_categories', 'budgets', 'report_preferences', 'report_runs', 'plan_change_requests', 'import_batches'] as $t) {
            $this->assertTrue(Schema::hasTable($t), $t.' mantida');
        }
        foreach (['entries' => 'approval_status', 'plans' => 'kind', 'memberships' => 'requires_approval', 'whatsapp_contacts' => 'company_ids'] as $t => $c) {
            $this->assertFalse(Schema::hasColumn($t, $c), "$t.$c removida (esquema igual ao da 1.1.6 para os backups dela)");
        }
        $this->assertSame(1, CostCenter::withoutGlobalScopes()->count());
        $migration->up();
        $this->assertSame($before, [Entry::withoutGlobalScopes()->where('description', 'Com centro')->value('cost_center_id'), Plan::query()->where('code', 'pessoal')->value('kind')]);
        $m = Membership::withoutGlobalScopes()->where('user_id', $u->id)->first();
        $this->assertTrue($m->requires_approval);
        $this->assertSame(5000, $m->approval_limit_cents);
        $this->assertSame([$cc->id], $m->cost_center_ids);
        $this->assertFalse(Schema::hasTable('preservado_1_1_7'));
        $this->assertTrue(Schema::hasColumn('entries', 'approval_status'));

        // reversão para 1.1.6 é recusada enquanto houver despesa pendente (a 1.1.6 a trataria como efetiva)
        $tmp = sys_get_temp_dir().'/wsf-revert-'.uniqid();
        mkdir($tmp.'/app/installed', 0777, true);
        $this->app->useStoragePath($tmp);
        $svc = app(UpdateService::class);
        $id = strtolower((string) \Illuminate\Support\Str::ulid());
        mkdir($svc->runDir($id).'/anterior', 0777, true);
        $svc->save(['id' => $id, 'status' => 'done', 'from' => '1.1.6', 'to' => trim((string) file_get_contents(base_path('VERSION'))), 'log' => [], 'user_id' => null, 'started_at' => now()->toIso8601String()]);

        $this->inAccount($a, fn () => Membership::query()->where('user_id', $u->id)->first()->update(['role' => 'financeiro', 'requires_approval' => true, 'approval_limit_cents' => null, 'cost_center_ids' => null]));
        $this->ctx()->runFor($a->id, fn () => app(FinanceService::class)->create($loja, ['tipo' => 'despesa', 'desc' => 'Pendente', 'valor_cents' => 1000, 'data' => '2026-11-18']), null, 'financeiro', $u->id);
        $this->assertSame(1, $this->pending()->count());
        try {
            $svc->revert($id, 'codigo', null);
            $this->fail('reversão deveria ser recusada');
        } catch (\App\Core\Support\BusinessRule $e) {
            $this->assertStringContainsString('pendente(s) de aprovação', $e->getMessage());
        }
        $this->assertSame('1.1.7', trim((string) file_get_contents(base_path('VERSION'))));
        $this->assertFalse(is_file(storage_path('framework/down')));
    }
}
