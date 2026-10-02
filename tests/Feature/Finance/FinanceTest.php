<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Models\FinancialTransaction;
use App\Modules\Finance\Models\Receivable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\Scheduling\SchedulingSetup;
use Tests\TestCase;

class FinanceTest extends TestCase
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

    /** Agendamento particular (R$ 250,00) com chegada registrada → conta a receber. */
    private function arrivedReceivable(string $time = '08:00'): Receivable
    {
        $id = $this->as($this->clinic['admin'])->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), $time))->json('data.id');
        $this->as($this->clinic['admin'])->postJson("/api/v1/appointments/{$id}/arrive")->assertCreated();

        return $this->context()->runFor($this->company()->id, fn () => Receivable::query()->where('appointment_id', $id)->firstOrFail());
    }

    private function openCash($user, int $opening = 5000): string
    {
        return $this->as($user)->postJson('/api/v1/cash-sessions', ['branch_id' => $this->branch()->id, 'opening_cents' => $opening])->assertCreated()->json('data.id');
    }

    public function test_arrival_generates_receivable_once_and_cancellation_cancels_it(): void
    {
        $r = $this->arrivedReceivable();
        $this->assertSame(25000, $r->amount_cents);
        $this->assertSame('appointment', $r->origin);
        $this->assertSame($this->doctor->id, $r->doctor_id);
        $this->assertSame(1, DB::table('receivables')->count());
        // Vence hoje (São Paulo) não é "vencido" — regressão: comparação de datas em fusos diferentes.
        $this->assertFalse($r->isOverdue());
        $this->assertSame('Em aberto', $r->statusLabel());

        // Convênio não gera cobrança do paciente.
        $insured = $this->as($this->clinic['admin'])->postJson('/api/v1/appointments', $this->bookPayload($this->patient(), '08:30', ['payer_type' => 'private', 'service_id' => null]))->json('data.id');
        $this->as($this->clinic['admin'])->postJson("/api/v1/appointments/{$insured}/arrive")->assertCreated();
        $this->assertSame(1, DB::table('receivables')->count(), 'Sem valor → sem cobrança');

        $this->as($this->clinic['admin'])->postJson("/api/v1/appointments/{$r->appointment_id}/cancel", ['reason' => 'Paciente desistiu'])->assertOk();
        $this->assertSame('cancelled', $r->fresh()->status);
    }

    public function test_cash_receipt_rules_partial_discount_and_receipt(): void
    {
        $r = $this->arrivedReceivable();
        $reception = $this->userWithRole($this->company(), 'recepcao', $this->branch());
        $fin = $this->userWithRole($this->company(), 'financeiro');

        $this->as($reception)->postJson("/api/v1/receivables/{$r->id}/receive", ['method' => 'cash', 'amount_cents' => 10000])
            ->assertUnprocessable()->assertJsonPath('code', 'cash_session_required');

        $this->openCash($reception);
        $this->as($reception)->postJson("/api/v1/receivables/{$r->id}/receive", ['method' => 'cash', 'amount_cents' => 30000])
            ->assertUnprocessable()->assertJsonPath('code', 'amount_exceeds_balance');
        $this->as($reception)->postJson("/api/v1/receivables/{$r->id}/receive", ['method' => 'cash', 'amount_cents' => 10000, 'discount_cents' => 1000])
            ->assertForbidden()->assertJsonPath('code', 'discount_forbidden');
        $this->as($reception)->postJson("/api/v1/receivables/{$r->id}/receive", ['method' => 'cash', 'amount_cents' => 10000])
            ->assertCreated()->assertJsonPath('data.receivable.status', 'partial')->assertJsonPath('data.receivable.balance_cents', 15000);

        // Financeiro concede desconto e recebe o restante no cartão (sem caixa aberto: ok para cartão).
        $this->as($fin)->postJson("/api/v1/receivables/{$r->id}/receive", ['method' => 'credit_card', 'amount_cents' => 14000, 'discount_cents' => 1000, 'card_installments' => 2, 'authorization_code' => 'NSU123'])
            ->assertCreated()->assertJsonPath('data.receivable.status', 'paid')->assertJsonPath('data.receivable.balance_cents', 0);
        $this->as($fin)->postJson("/api/v1/receivables/{$r->id}/receive", ['method' => 'pix', 'amount_cents' => 100])->assertStatus(409);

        $txn = $this->context()->runFor($this->company()->id, fn () => FinancialTransaction::query()->where('method', 'cash')->firstOrFail());
        $this->actingAs($reception)->get(route('transactions.receipt', $txn))->assertOk()->assertSee('cem reais')->assertSee('R$ 100,00');
        $this->assertDatabaseHas('audit_logs', ['action' => 'finance.received', 'auditable_id' => $r->id]);
    }

    public function test_blind_close_and_review_by_another_person(): void
    {
        $reception = $this->userWithRole($this->company(), 'recepcao', $this->branch());
        $supervisor = $this->userWithRole($this->company(), 'financeiro');
        $session = $this->openCash($reception, 5000);
        $this->as($reception)->postJson('/api/v1/cash-sessions', ['branch_id' => $this->branch()->id])->assertStatus(409);

        $r = $this->arrivedReceivable();
        $this->as($reception)->postJson("/api/v1/receivables/{$r->id}/receive", ['method' => 'cash', 'amount_cents' => 20000])->assertCreated();
        $this->as($reception)->postJson("/api/v1/receivables/{$r->id}/receive", ['method' => 'pix', 'amount_cents' => 5000])->assertCreated();

        $this->as($reception)->postJson("/api/v1/cash-sessions/{$session}/movements", ['kind' => 'withdrawal', 'amount_cents' => 100000, 'reason' => 'cofre'])
            ->assertUnprocessable()->assertJsonPath('code', 'insufficient_cash');
        $this->as($reception)->postJson("/api/v1/cash-sessions/{$session}/movements", ['kind' => 'withdrawal', 'amount_cents' => 15000, 'reason' => 'Depósito no cofre'])->assertCreated();
        $this->as($reception)->getJson('/api/v1/cash-sessions/current')->assertJsonPath('data.summary.cash_expected', 10000); // 50 + 200 − 150

        $this->as($supervisor)->postJson("/api/v1/cash-sessions/{$session}/close", ['declared' => ['cash' => 9000, 'pix' => 5000]])->assertForbidden();
        $closed = $this->as($reception)->postJson("/api/v1/cash-sessions/{$session}/close", ['declared' => ['cash' => 9000, 'pix' => 5000]])->assertOk();
        $closed->assertJsonPath('data.status', 'closed')->assertJsonPath('data.difference_cents', -1000)->assertJsonPath('data.expected.cash', 10000);

        $this->as($reception)->postJson("/api/v1/cash-sessions/{$session}/movements", ['kind' => 'deposit', 'amount_cents' => 100, 'reason' => 'troco'])->assertStatus(409);

        $admin = $this->clinic['admin'];
        $this->as($admin)->postJson("/api/v1/cash-sessions/{$session}/review")->assertUnprocessable()->assertJsonPath('code', 'justification_required');
        $this->as($admin)->postJson("/api/v1/cash-sessions/{$session}/review", ['notes' => 'Troco devolvido a maior; operador orientado.'])->assertOk()->assertJsonPath('data.status', 'reviewed');
        $this->as($supervisor)->postJson("/api/v1/cash-sessions/{$session}/review", ['notes' => 'de novo de novo'])->assertStatus(409);
    }

    public function test_operator_cannot_review_own_session(): void
    {
        $fin = $this->userWithRole($this->company(), 'financeiro');
        $session = $this->openCash($fin, 0);
        $this->as($fin)->postJson("/api/v1/cash-sessions/{$session}/close", ['declared' => ['cash' => 0]])->assertOk()->assertJsonPath('data.difference_cents', 0);
        $this->as($fin)->postJson("/api/v1/cash-sessions/{$session}/review")->assertForbidden()->assertJsonPath('code', 'self_review');
    }

    public function test_reversal_restores_balance_and_ledger_is_immutable(): void
    {
        $fin = $this->userWithRole($this->company(), 'financeiro');
        $reception = $this->userWithRole($this->company(), 'recepcao', $this->branch());
        $this->openCash($fin, 0);
        $r = $this->arrivedReceivable();
        $txnId = $this->as($fin)->postJson("/api/v1/receivables/{$r->id}/receive", ['method' => 'cash', 'amount_cents' => 25000])->json('data.transaction.id');

        $this->as($reception)->postJson("/api/v1/transactions/{$txnId}/reverse", ['reason' => 'Cobrado em duplicidade'])->assertForbidden();
        $this->as($fin)->postJson("/api/v1/transactions/{$txnId}/reverse", ['reason' => 'curto'])->assertUnprocessable();
        $rev = $this->as($fin)->postJson("/api/v1/transactions/{$txnId}/reverse", ['reason' => 'Cobrado em duplicidade'])->assertCreated()
            ->assertJsonPath('data.direction', 'out')->assertJsonPath('data.reversal_of', $txnId)->json('data.id');
        $this->as($fin)->postJson("/api/v1/transactions/{$txnId}/reverse", ['reason' => 'Cobrado em duplicidade'])->assertStatus(409);
        $this->as($fin)->postJson("/api/v1/transactions/{$rev}/reverse", ['reason' => 'Estorno do estorno'])->assertUnprocessable()->assertJsonPath('code', 'reversal_of_reversal');

        $this->assertSame('open', $r->fresh()->status);
        $this->assertSame(0, $r->fresh()->paid_cents);
        $this->as($fin)->getJson('/api/v1/cash-sessions/current')->assertJsonPath('data.summary.cash_expected', 0);

        $txn = $this->context()->runFor($this->company()->id, fn () => FinancialTransaction::query()->findOrFail($txnId));
        $this->expectException(LogicException::class);
        $this->context()->runFor($this->company()->id, fn () => $txn->update(['amount_cents' => 1]));
    }

    public function test_database_blocks_ledger_changes_when_triggers_are_available(): void
    {
        $active = DB::getDriverName() === 'pgsql'
            ? DB::table('pg_trigger')->where('tgname', 'financial_transactions_immutable')->exists()
            : DB::table('information_schema.triggers')->where('trigger_schema', DB::getDatabaseName())->where('event_object_table', 'financial_transactions')->exists();
        if (! $active) {
            $this->markTestSkipped('Banco sem privilégio para triggers (hospedagem compartilhada).');
        }

        $fin = $this->userWithRole($this->company(), 'financeiro');
        $r = $this->arrivedReceivable();
        $this->as($fin)->postJson("/api/v1/receivables/{$r->id}/receive", ['method' => 'pix', 'amount_cents' => 25000])->assertCreated();

        $this->expectException(QueryException::class);
        DB::table('financial_transactions')->update(['amount_cents' => 1]);
    }

    public function test_payables_installments_payment_and_cancellation(): void
    {
        $fin = $this->userWithRole($this->company(), 'financeiro');
        $category = DB::table('financial_categories')->where('company_id', $this->company()->id)->where('name', 'Aluguel e condomínio')->value('id');

        $list = $this->as($fin)->postJson('/api/v1/payables', ['category_id' => $category, 'supplier' => 'Imobiliária Centro', 'description' => 'Aluguel',
            'amount_cents' => 10000, 'installments' => 3, 'due_date' => '2026-10-31'])->assertCreated()->json('data');
        $this->assertSame([3333, 3333, 3334], array_column($list, 'amount_cents'));
        $this->assertSame(['2026-10-31', '2026-11-30', '2026-12-31'], array_column($list, 'due_date'));

        $this->as($fin)->postJson("/api/v1/payables/{$list[0]['id']}/pay", ['method' => 'bank_transfer', 'amount_cents' => 5000])->assertUnprocessable();
        $this->as($fin)->postJson("/api/v1/payables/{$list[0]['id']}/pay", ['method' => 'cash', 'amount_cents' => 3333])->assertUnprocessable()->assertJsonPath('code', 'cash_session_required');
        $this->as($fin)->postJson("/api/v1/payables/{$list[0]['id']}/pay", ['method' => 'bank_transfer', 'amount_cents' => 3333, 'paid_on' => '2026-10-04'])->assertCreated();
        $this->as($fin)->postJson("/api/v1/payables/{$list[0]['id']}/cancel", ['reason' => 'Lançado errado'])->assertStatus(409)->assertJsonPath('code', 'has_payments');
        $this->as($fin)->postJson("/api/v1/payables/{$list[1]['id']}/cancel", ['reason' => 'Contrato encerrado'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->as($fin)->postJson("/api/v1/payables/{$list[2]['id']}/pay", ['method' => 'pix', 'amount_cents' => 3334, 'paid_on' => '2027-01-10'])->assertUnprocessable()->assertJsonPath('code', 'invalid_date');

        $flow = $this->as($fin)->getJson('/api/v1/finance/summary?from=2026-10-01&to=2026-10-31')->assertOk()->json('data');
        $this->assertSame(3333, $flow['out']);
        $this->assertSame(['Aluguel e condomínio' => -3333], $flow['by_category']);
    }

    public function test_permissions_and_tenant_isolation(): void
    {
        $r = $this->arrivedReceivable();
        $reception = $this->userWithRole($this->company(), 'recepcao', $this->branch());
        $doctorUser = $this->userWithRole($this->company(), 'medico');

        $this->as($reception)->getJson('/api/v1/payables')->assertForbidden();
        $this->as($reception)->getJson('/api/v1/receivables')->assertOk()->assertJsonPath('data.0.id', $r->id);
        $this->as($doctorUser)->getJson('/api/v1/receivables')->assertForbidden();
        $this->as($reception)->postJson("/api/v1/receivables/{$r->id}/cancel", ['reason' => 'teste de permissão'])->assertForbidden();

        $outsider = $this->createClinic('Clínica Beta')['admin'];
        $this->as($outsider)->postJson("/api/v1/receivables/{$r->id}/receive", ['method' => 'pix', 'amount_cents' => 100])->assertNotFound();
        $this->as($outsider)->getJson('/api/v1/receivables')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_web_screens_and_money_input(): void
    {
        $fin = $this->userWithRole($this->company(), 'financeiro');
        $reception = $this->userWithRole($this->company(), 'recepcao', $this->branch());
        $r = $this->arrivedReceivable();

        $this->actingAs($reception)->get(route('cash.index'))->assertOk()->assertSee('Abrir caixa');
        $this->post(route('cash.open'), ['branch_id' => $this->branch()->id, 'opening' => '50,00'])->assertRedirect(route('cash.index'));
        $this->get(route('receivables.index'))->assertOk()->assertSee('R$ 250,00');
        $this->get(route('receivables.show', $r))->assertOk()->assertSee('Registrar recebimento');
        $this->post(route('receivables.receive', $r), ['method' => 'cash', 'amount' => '250,00'])->assertRedirect()->assertSessionHas('print_receipt');
        $this->assertSame('paid', $r->fresh()->status);
        $this->get(route('cash.index'))->assertOk()->assertSee('Fechar caixa')->assertSee($reception->name);
        $this->get(route('finance.overview'))->assertForbidden();
        $this->get(route('payables.index'))->assertForbidden();
        $session = DB::table('cash_sessions')->value('id');
        $this->post(route('cash.close', $session), ['declared' => ['cash' => '300,00', 'pix' => '']])->assertRedirect(route('cash.show', $session));
        $this->get(route('cash.print', $session))->assertOk()->assertSee('FECHAMENTO DE CAIXA');

        $this->actingAs($fin)->get(route('finance.overview'))->assertOk()->assertSee('R$ 250,00')->assertSee('Caixas a conferir');
        $this->get(route('cash.sessions'))->assertOk()->assertSee($reception->name);
        $this->get(route('cash.show', $session))->assertOk()->assertSee('Confirmar conferência');
        $this->post(route('payables.store'), ['supplier' => 'Laboratório X', 'description' => 'Exames terceirizados', 'amount' => '1.234,56',
            'due_date' => '2026-10-20', 'category_id' => DB::table('financial_categories')->where('type', 'expense')->value('id')])->assertRedirect();
        $this->assertDatabaseHas('payables', ['supplier' => 'Laboratório X', 'amount_cents' => 123456]);
        $this->get(route('payables.index'))->assertOk()->assertSee('R$ 1.234,56');
        $this->get(route('finance.categories'))->assertOk()->assertSee('Repasse médico');

        $csv = $this->get(route('finance.overview', ['from' => '2026-10-01', 'to' => '2026-10-31', 'export' => 'csv']))->assertOk();
        $this->assertStringContainsString('Recebimento;Entrada;Dinheiro;250,00', $csv->streamedContent());

        $this->actingAs($this->clinic['admin'])->get(route('agenda.show', $r->appointment_id))->assertOk()->assertSee('Recebido');
        $this->get('/')->assertOk()->assertSee('Recebido hoje');
    }
}
