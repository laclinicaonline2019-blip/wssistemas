<?php

namespace Tests\Feature\Banking;

use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankLineMatch;
use App\Modules\Banking\Models\BankStatement;
use App\Modules\Banking\Models\BankStatementLine;
use App\Modules\Banking\Parsers\CsvParser;
use App\Modules\Banking\Parsers\OfxParser;
use App\Modules\Finance\Models\FinancialTransaction;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Services\FinanceService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Fase 14 — conciliação bancária: OFX/CSV/Open Finance, sugestões, automática, manual, lançar, ignorar. */
class BankReconciliationTest extends TestCase
{
    private array $clinic;

    private BankAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00', 'America/Sao_Paulo'));
        $this->clinic = $this->createClinic();
        $this->account = $this->tenant(fn () => BankAccount::create(['name' => 'Itaú movimento', 'bank_code' => '341', 'agency' => '0001', 'account_number' => '12345-6']));
    }

    private function tenant(Closure $fn): mixed
    {
        return $this->context()->runFor($this->clinic['company']->id, $fn);
    }

    /** Recebimento já lançado no financeiro (fora do caixa), na data informada. */
    private function receipt(int $cents, string $method, string $paidOn, ?string $auth = null): FinancialTransaction
    {
        return $this->tenant(function () use ($cents, $method, $paidOn, $auth) {
            $f = app(FinanceService::class);
            $r = $f->createReceivable($this->clinic['admin'], ['branch_id' => $this->clinic['branch']->id, 'category_id' => $f->defaultCategory('income', 'Consultas'),
                'description' => 'Consulta '.$cents, 'amount_cents' => $cents, 'due_date' => $paidOn]);

            return $f->receive($this->clinic['admin'], $r, ['amount_cents' => $cents, 'method' => $method, 'paid_on' => $paidOn, 'outside_cash' => true, 'authorization_code' => $auth]);
        });
    }

    private function ofx(array $trns, string $balance = '1500.00'): string
    {
        $body = '';
        foreach ($trns as [$date, $amount, $fitid, $memo]) {
            $body .= "<STMTTRN>\r\n<TRNTYPE>".(str_starts_with($amount, '-') ? 'DEBIT' : 'CREDIT')."\r\n<DTPOSTED>{$date}120000[-3:BRT]\r\n<TRNAMT>{$amount}\r\n<FITID>{$fitid}\r\n<MEMO>{$memo}\r\n</STMTTRN>\r\n";
        }
        $ofx = "OFXHEADER:100\r\nDATA:OFXSGML\r\nVERSION:102\r\nENCODING:USASCII\r\nCHARSET:1252\r\n\r\n<OFX>\r\n<BANKMSGSRSV1><STMTTRNRS><STMTRS><CURDEF>BRL\r\n<BANKACCTFROM>\r\n<BANKID>0341\r\n<ACCTID>123456\r\n</BANKACCTFROM>\r\n"
            ."<BANKTRANLIST>\r\n<DTSTART>20261001\r\n<DTEND>20261009\r\n{$body}</BANKTRANLIST>\r\n<LEDGERBAL>\r\n<BALAMT>{$balance}\r\n<DTASOF>20261009\r\n</LEDGERBAL>\r\n</STMTRS></STMTTRNRS></BANKMSGSRSV1>\r\n</OFX>\r\n";

        return mb_convert_encoding($ofx, 'Windows-1252', 'UTF-8');
    }

    private function upload(string $content, string $name = 'extrato.ofx')
    {
        return $this->actingAs($this->clinic['admin'])->post(route('bank.accounts.import', $this->account), ['file' => UploadedFile::fake()->createWithContent($name, $content)]);
    }

    private function line(string $fitidOrDesc): BankStatementLine
    {
        return $this->tenant(fn () => BankStatementLine::query()->where('reference', $fitidOrDesc)->orWhere('description', 'like', '%'.$fitidOrDesc.'%')->firstOrFail());
    }

    public function test_parsers_handle_brazilian_ofx_and_csv_formats(): void
    {
        $p = (new OfxParser)->parse($this->ofx([['20261005', '-12.90', 'F1', 'TARIFA PACOTE SERVIÇOS'], ['20261006', '250,00', 'F2', 'PIX RECEBIDO JOÃO']]));
        $this->assertSame([['2026-10-05', -1290, 'TARIFA PACOTE SERVIÇOS', 'F1'], ['2026-10-06', 25000, 'PIX RECEBIDO JOÃO', 'F2']],
            array_map(fn ($l) => [$l['date'], $l['amount_cents'], $l['description'], $l['reference']], $p->lines));
        $this->assertSame(['2026-10-01', '2026-10-09', 150000, '2026-10-09', '123456'], [$p->periodStart, $p->periodEnd, $p->balanceCents, $p->balanceDate, $p->accountNumber]);

        $csv = "Extrato conta corrente\nData;Histórico;Nº Documento;Crédito (R$);Débito (R$)\n01/10/2026;SALDO ANTERIOR;;;\n02/10/2026;PIX RECEBIDO MARIA;E123;1.234,56;\n03/10/2026;TAR BANCARIA;;;-19,90\n03/10/2026;SALDO DO DIA;;1.214,66;\n";
        $c = (new CsvParser)->parse(mb_convert_encoding($csv, 'Windows-1252', 'UTF-8'));
        $this->assertSame([['2026-10-02', 123456, 'PIX RECEBIDO MARIA', 'E123'], ['2026-10-03', -1990, 'TAR BANCARIA', null]],
            array_map(fn ($l) => [$l['date'], $l['amount_cents'], $l['description'], $l['reference']], $c->lines));

        $signed = (new CsvParser)->parse("date,description,amount\n2026-10-04,Card settlement,\"1,500.00\"\n2026-10-04,Fee,(3.50)\n");
        $this->assertSame([150000, -350], array_column($signed->lines, 'amount_cents'));

        $this->expectExceptionMessage('Cabeçalho do CSV não reconhecido');
        (new CsvParser)->parse("a;b;c\n1;2;3\n");
    }

    public function test_import_is_idempotent_and_auto_reconciles_only_unambiguous_cases(): void
    {
        $pix = $this->receipt(25000, 'pix', '2026-10-06');
        $card = $this->receipt(18000, 'credit_card', '2026-09-20', 'NSU998877');
        $twinA = $this->receipt(9000, 'pix', '2026-10-07');
        $twinB = $this->receipt(9000, 'pix', '2026-10-07');
        $cash = $this->tenant(fn () => FinancialTransaction::query()->count());

        $file = $this->ofx([
            ['20261006', '250.00', 'F-PIX', 'PIX RECEBIDO'],
            ['20261008', '180.00', 'F-CARD', 'CIELO CREDITO NSU998877'],  // cartão: banco credita dias depois
            ['20261007', '90.00', 'F-T1', 'PIX RECEBIDO'],                 // dois PIX iguais no mesmo dia: ambíguo
            ['20261007', '90.00', 'F-T2', 'PIX RECEBIDO'],
            ['20261005', '-12.90', 'F-TAR', 'TARIFA PACOTE'],
        ]);
        $this->upload($file)->assertSessionHas('success', fn ($m) => str_contains($m, '5 lançamento(s), 5 novo(s)'));
        $this->upload($file)->assertSessionHas('success', fn ($m) => str_contains($m, '0 novo(s)'));
        $this->assertSame(5, $this->tenant(fn () => BankStatementLine::query()->count()));
        $this->assertSame(2, $this->tenant(fn () => BankStatement::query()->count()));
        $this->assertSame(150000, $this->tenant(fn () => BankStatement::query()->value('balance_cents')));

        // Sugestão com bônus pelo NSU no histórico.
        $this->actingAs($this->clinic['admin'])->get(route('bank.accounts.show', $this->account))->assertOk()->assertSee('CIELO CREDITO NSU998877')->assertSee('Consulta 18000');

        $this->actingAs($this->clinic['admin'])->post(route('bank.accounts.auto', $this->account))->assertSessionHas('success', fn ($m) => str_starts_with($m, '2 linha(s)'));
        $this->assertSame('reconciled', $this->line('F-PIX')->status);
        $this->assertSame('reconciled', $this->line('F-CARD')->status);
        $this->assertSame(['pending', 'pending', 'pending'], [$this->line('F-T1')->status, $this->line('F-T2')->status, $this->line('F-TAR')->status]);
        $this->assertEqualsCanonicalizing([$pix->id, $card->id], $this->tenant(fn () => BankLineMatch::query()->where('origin', 'auto')->pluck('transaction_id')->all()));

        // Ambíguo: escolha manual; o mesmo lançamento não pode ir para duas linhas.
        $this->actingAs($this->clinic['admin'])->post(route('bank.lines.match', $this->line('F-T1')), ['transactions' => [$twinA->id]])->assertSessionHas('success');
        $this->actingAs($this->clinic['admin'])->post(route('bank.lines.match', $this->line('F-T2')), ['transactions' => [$twinA->id]])->assertSessionHas('error', fn ($m) => str_contains($m, 'já está conciliado'));
        $this->actingAs($this->clinic['admin'])->post(route('bank.lines.match', $this->line('F-T2')), ['transactions' => [$twinB->id]])->assertSessionHas('success');

        // O livro não muda: nenhuma movimentação nova.
        $this->assertSame($cash, $this->tenant(fn () => FinancialTransaction::query()->count()));
    }

    public function test_gateway_payout_multi_match_create_entry_ignore_and_undo(): void
    {
        $admin = $this->clinic['admin'];
        $a = $this->receipt(20000, 'pix', '2026-10-06');
        $b = $this->receipt(30000, 'pix', '2026-10-06');
        $fee = $this->tenant(fn () => FinancialTransaction::create(['branch_id' => $this->clinic['branch']->id, 'direction' => 'out', 'kind' => 'fee', 'method' => 'pix',
            'amount_cents' => 199, 'description' => 'Tarifa ASAAS', 'gateway' => 'asaas', 'occurred_at' => now()->subDays(4)]));
        // Caixa aberto do operador: lançamentos do banco NÃO podem entrar nele.
        $this->tenant(fn () => app(FinanceService::class)->openSession($admin, $this->clinic['branch']->id, 0));

        $this->upload($this->ofx([['20261007', '498.01', 'PAYOUT', 'TED ASAAS PAGAMENTOS'], ['20261005', '-12.90', 'TAR', 'TARIFA PACOTE'],
            ['20261006', '3.21', 'REND', 'RENDIMENTO APLIC AUTOMATICA'], ['20261008', '-1000.00', 'TRF', 'TRANSF CONTA PROPRIA']]))->assertSessionHasNoErrors();

        // Repasse do gateway = recebimentos − tarifa.
        $payout = $this->line('PAYOUT');
        $this->actingAs($admin)->post(route('bank.lines.match', $payout), ['transactions' => [$a->id, $b->id]])->assertSessionHas('error', fn ($m) => str_contains($m, 'não bate'));
        $this->actingAs($admin)->post(route('bank.lines.match', $payout), ['transactions' => [$a->id, $b->id, $fee->id]])->assertSessionHas('success');
        $this->assertSame(3, $this->tenant(fn () => $payout->fresh()->matches()->count()));

        // Tarifa bancária sem lançamento: cria conta a pagar baixada na data do extrato, fora do caixa.
        $expense = $this->tenant(fn () => app(FinanceService::class)->defaultCategory('expense', 'Tarifas bancárias'));
        $this->actingAs($admin)->post(route('bank.lines.create_entry', $this->line('TAR')), ['category_id' => $expense, 'description' => 'Tarifa pacote', 'counterparty' => 'Itaú', 'method' => 'bank_transfer'])
            ->assertSessionHas('success');
        $p = $this->tenant(fn () => Payable::query()->sole());
        $t = $this->tenant(fn () => FinancialTransaction::query()->where('payable_id', $p->id)->sole());
        $this->assertSame(['paid', 1290, null, '2026-10-05'], [$p->status, $t->amount_cents, $t->cash_session_id, $t->occurred_at->timezone('America/Sao_Paulo')->toDateString()]);
        $this->assertSame('created', $this->tenant(fn () => BankLineMatch::query()->where('transaction_id', $t->id)->value('origin')));

        // Rendimento: conta a receber recebida.
        $income = $this->tenant(fn () => app(FinanceService::class)->defaultCategory('income', 'Outras receitas'));
        $this->actingAs($admin)->post(route('bank.lines.create_entry', $this->line('REND')), ['category_id' => $income, 'description' => 'Rendimento', 'counterparty' => 'Itaú', 'method' => 'bank_transfer'])
            ->assertSessionHas('success');
        $this->assertSame(['paid', 321], $this->tenant(fn () => [Receivable::query()->where('description', 'Rendimento')->value('status'), (int) Receivable::query()->where('description', 'Rendimento')->value('paid_cents')]));
        $this->assertSame(0, $this->tenant(fn () => FinancialTransaction::query()->whereNotNull('cash_session_id')->count()));

        // Ignorar exige motivo; desfazer volta para "a conciliar" e libera o lançamento.
        $this->actingAs($admin)->post(route('bank.lines.ignore', $this->line('TRF')), [])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post(route('bank.lines.ignore', $this->line('TRF')), ['reason' => 'Transferência entre contas próprias'])->assertSessionHas('success');
        $this->assertSame('ignored', $this->line('TRF')->status);
        $this->actingAs($admin)->post(route('bank.lines.undo', $payout))->assertSessionHas('success');
        $this->assertSame(['pending', 0, 3], $this->tenant(fn () => [$payout->fresh()->status, $payout->fresh()->matches()->count(), BankLineMatch::query()->where('line_id', $payout->id)->whereNotNull('undone_at')->count()]));
        $this->actingAs($admin)->post(route('bank.lines.match', $payout), ['transactions' => [$a->id, $b->id, $fee->id]])->assertSessionHas('success');
        $this->assertTrue(DB::table('audit_logs')->where('action', 'bank.line_reopened')->exists());

        $this->actingAs($admin)->get(route('bank.lines.show', $this->line('TRF')))->assertOk()->assertSee('Transferência entre contas próprias');
    }

    public function test_permissions_isolation_and_open_finance_sync(): void
    {
        $this->actingAs($this->userWithRole($this->clinic['company'], 'recepcao'))->get(route('bank.index'))->assertForbidden();
        $this->actingAs($this->clinic['admin'])->get(route('bank.index'))->assertOk()->assertSee('Itaú movimento');

        // Outra clínica: não vê a conta nem concilia com lançamento alheio.
        $other = $this->createClinic('Clínica Beta');
        $this->actingAs($other['admin'])->get(route('bank.accounts.show', $this->account))->assertNotFound();
        $this->upload($this->ofx([['20261006', '250.00', 'X1', 'PIX']]));
        $foreign = $this->context()->runFor($other['company']->id, function () use ($other) {
            $f = app(FinanceService::class);
            $r = $f->createReceivable($other['admin'], ['branch_id' => $other['branch']->id, 'category_id' => $f->defaultCategory('income', 'Consultas'), 'description' => 'Beta', 'amount_cents' => 25000, 'due_date' => '2026-10-06']);

            return $f->receive($other['admin'], $r, ['amount_cents' => 25000, 'method' => 'pix', 'paid_on' => '2026-10-06']);
        });
        $this->actingAs($this->clinic['admin'])->post(route('bank.lines.match', $this->line('X1')), ['transactions' => [$foreign->id]])->assertSessionHas('error');
        $this->assertSame('pending', $this->line('X1')->status);

        // Open Finance (Pluggy): credenciais criptografadas, paginação, sem duplicar na releitura.
        $this->actingAs($this->clinic['admin'])->put(route('bank.accounts.update', $this->account), ['name' => 'Itaú movimento', 'sync_provider' => 'pluggy',
            'external_account_id' => 'acc-123', 'client_id' => 'cid-abc', 'client_secret' => 'segredo-pluggy-xyz', 'is_active' => 1])->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('segredo-pluggy-xyz', (string) DB::table('bank_accounts')->where('id', $this->account->id)->value('credentials'));
        Http::fake([
            'api.pluggy.ai/auth' => Http::response(['apiKey' => 'KEY-1']),
            'api.pluggy.ai/transactions*' => fn (HttpRequest $r) => Http::response($r['page'] == 1
                ? ['total' => 3, 'totalPages' => 2, 'page' => 1, 'results' => [
                    ['id' => 'tx-1', 'date' => '2026-10-08T10:00:00.000Z', 'description' => 'PIX RECEBIDO ANA', 'amount' => 150.5, 'type' => 'CREDIT'],
                    ['id' => 'tx-2', 'date' => '2026-10-08T11:00:00.000Z', 'description' => 'DEBITO TARIFA', 'amount' => -7.9, 'type' => 'DEBIT']]]
                : ['total' => 3, 'totalPages' => 2, 'page' => 2, 'results' => [['id' => 'tx-3', 'date' => '2026-10-09T09:00:00.000Z', 'description' => 'TED', 'amount' => 40, 'type' => 'CREDIT']]]),
        ]);
        $this->actingAs($this->clinic['admin'])->post(route('bank.accounts.sync', $this->account))->assertSessionHas('success', fn ($m) => str_contains($m, '3 lançamento(s) lido(s), 3 novo(s)'));
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.pluggy.ai/auth' && $r['clientId'] === 'cid-abc' && $r['clientSecret'] === 'segredo-pluggy-xyz');
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/transactions') && $r->hasHeader('X-API-KEY', 'KEY-1') && $r['accountId'] === 'acc-123');
        $this->assertSame([15050, -790, 4000], $this->tenant(fn () => BankStatementLine::query()->where('reference', 'like', 'pluggy:%')->orderBy('reference')->pluck('amount_cents')->all()));
        $this->artisan('aivexa:bank:sync')->assertSuccessful();
        $this->assertSame(3, $this->tenant(fn () => BankStatementLine::query()->where('reference', 'like', 'pluggy:%')->count()));
        $this->assertNotNull($this->account->fresh()->last_synced_at);
    }
}
