<?php

namespace Tests\Feature\Reports;

use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Payments\Models\PaymentSplit;
use App\Modules\Payments\Models\SplitRule;
use App\Modules\Reports\Models\DoctorClosing;
use App\Modules\Scheduling\Models\Appointment;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Scheduling\SchedulingSetup;
use Tests\TestCase;
use ZipArchive;

/** Fase 15 — relatórios (tela, CSV, Excel, PDF) e fechamento mensal médico × clínica. */
class ReportsTest extends TestCase
{
    use SchedulingSetup;

    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScheduling();
        $this->tenant(fn () => SplitRule::create(['doctor_id' => $this->doctor->id, 'type' => 'percent', 'value' => 6000]));
    }

    private function tenant(Closure $fn): mixed
    {
        return $this->context()->runFor($this->company()->id, $fn);
    }

    private function adminApi(): static
    {
        $admin = $this->clinic['admin'];
        $this->app['auth']->forgetGuards();
        $this->tokens[$admin->id] ??= $this->apiToken($admin);
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->tokens[$admin->id]);
    }

    /** Agenda; "arrive" gera a conta a receber; recebe por PIX. Retorna o agendamento. */
    private function attendAndPay(string $name, string $time): Appointment
    {
        $id = $this->adminApi()->postJson('/api/v1/appointments', $this->bookPayload($this->patient($name), $time))->assertCreated()->json('data.id');
        $this->adminApi()->postJson("/api/v1/appointments/{$id}/arrive")->assertCreated();
        $this->tenant(function () use ($id) {
            $r = Receivable::query()->where('appointment_id', $id)->sole();
            app(FinanceService::class)->receive($this->clinic['admin'], $r, ['amount_cents' => $r->amount_cents, 'method' => 'pix', 'outside_cash' => true]);
        });

        return $this->tenant(fn () => Appointment::query()->findOrFail($id));
    }

    private function scenario(): void
    {
        $this->attendAndPay('=CMD Fórmula', '08:00');
        $this->attendAndPay('Bruno Pago', '08:30');
        $noShow = $this->adminApi()->postJson('/api/v1/appointments', $this->bookPayload($this->patient('Carla Faltou'), '09:00'))->json('data.id');
        $this->travelTo(CarbonImmutable::parse(self::MONDAY.' 10:00', 'America/Sao_Paulo'));
        $this->tokens = [];
        $this->adminApi()->postJson("/api/v1/appointments/{$noShow}/no-show")->assertOk();
    }

    public function test_reports_screen_exports_and_permissions(): void
    {
        $this->scenario();
        $admin = $this->clinic['admin'];
        $q = ['from' => self::MONDAY, 'to' => self::MONDAY];

        $doctorUser = $this->userWithRole($this->company(), 'medico');
        $this->actingAs($doctorUser)->get(route('reports.show', ['appointments'] + $q))->assertForbidden();
        $this->actingAs($doctorUser)->get(route('reports.show', ['diagnoses'] + $q))->assertOk();
        $this->actingAs($admin)->get(route('reports.index'))->assertOk()->assertSee('Produção por médico')->assertSee('Fechamento mensal');
        $this->actingAs($admin)->get(route('reports.show', ['nao_existe']))->assertNotFound();

        $this->actingAs($admin)->get(route('reports.show', ['appointments'] + $q))->assertOk()
            ->assertSee('Bruno Pago')->assertSee('Taxa de faltas')->assertSee('33,3%'); // 1 falta ÷ (2 atendidos + 1 falta)

        // CSV: BOM, ";" e proteção contra fórmula.
        $csv = $this->actingAs($admin)->get(route('reports.show', ['appointments'] + $q + ['format' => 'csv']));
        $csv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $body = $csv->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFData;Hora;Prontuário;Paciente", $body);
        $this->assertStringContainsString("'=CMD Fórmula", $body);
        $this->assertStringContainsString(';250,00', $body);

        // Excel: pacote OOXML válido, dinheiro como número.
        $xlsx = $this->actingAs($admin)->get(route('reports.show', ['appointments'] + $q + ['format' => 'xlsx']))->assertOk()->getContent();
        $path = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($path, $xlsx);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);
        $this->assertNotFalse(simplexml_load_string($sheet));
        $this->assertStringContainsString('<t xml:space="preserve">Bruno Pago', $sheet);
        $this->assertStringContainsString('s="2"><v>250.00</v>', $sheet);
        $this->assertStringNotContainsString('<f>', $sheet);

        // PDF.
        $pdf = $this->actingAs($admin)->get(route('reports.show', ['doctor_production'] + $q + ['format' => 'pdf']))->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertSame(3, DB::table('audit_logs')->where('action', 'report.exported')->count());

        // Financeiros: por categoria, por forma, aging.
        $this->actingAs($admin)->get(route('reports.show', ['cash_by_category'] + $q))->assertOk()->assertSee('Consultas')->assertSee('R$ 500,00');
        $this->actingAs($admin)->get(route('reports.show', ['receipts_by_method'] + $q))->assertOk()->assertSee('PIX')->assertSee('100,0%');
        $this->tenant(fn () => app(FinanceService::class)->createReceivable($admin, ['branch_id' => $this->branch()->id, 'category_id' => app(FinanceService::class)->defaultCategory('income', 'Exames'),
            'description' => 'Exame atrasado', 'amount_cents' => 9000, 'due_date' => '2026-08-01']));
        $this->actingAs($admin)->get(route('reports.show', ['receivables_aging']))->assertOk()->assertSee('Exame atrasado')->assertSee('61–90 dias');
        $this->actingAs($admin)->get(route('reports.show', ['doctor_production'] + $q))->assertOk()->assertSee('Dra. Agenda')->assertSee('R$ 500,00');

        // Clínico: só agregado (código e contagem), nunca o nome do paciente; só a versão vigente.
        $enc = (string) Str::ulid();
        $pat = $this->tenant(fn () => Appointment::query()->where('status', 'arrived')->firstOrFail()->patient_id);
        DB::table('encounters')->insert(['id' => $enc, 'company_id' => $this->company()->id, 'branch_id' => $this->branch()->id, 'patient_id' => $pat, 'doctor_id' => $this->doctor->id,
            'status' => 'finalized', 'current_version' => 2, 'started_at' => now(), 'finalized_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[1, 'J06.9', 'IVAS'], [2, 'I10', 'Hipertensão essencial']] as [$v, $code, $desc]) {
            DB::table('encounter_diagnoses')->insert(['id' => (string) Str::ulid(), 'company_id' => $this->company()->id, 'encounter_id' => $enc, 'version' => $v, 'code' => $code,
                'description' => $desc, 'cid_version' => 'CID-10', 'is_primary' => true, 'created_at' => now()]);
        }
        $this->actingAs($admin)->get(route('reports.show', ['diagnoses'] + $q))->assertOk()->assertSee('I10')->assertDontSee('J06.9')->assertDontSee('Bruno Pago');

        // Período máximo.
        $this->actingAs($admin)->get(route('reports.show', ['appointments', 'from' => '2024-01-01', 'to' => '2026-10-05']))->assertSessionHas('error');
    }

    public function test_doctor_closing_is_immutable_settles_and_is_confirmed_or_disputed_by_the_doctor(): void
    {
        $this->scenario();
        $admin = $this->clinic['admin'];
        $doctorUser = $this->userWithRole($this->company(), 'medico');
        $this->tenant(fn () => $this->doctor->forceFill(['user_id' => $doctorUser->id])->save());

        // Mês em aberto não fecha.
        $this->actingAs($admin)->post(route('closings.store', $this->doctor), ['period' => '2026-10', 'settle' => 1])->assertSessionHas('error', fn ($m) => str_contains($m, 'encerrados'));

        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00', 'America/Sao_Paulo'));
        $this->actingAs($admin)->get(route('closings.preview', [$this->doctor, 'period' => '2026-10']))->assertOk()
            ->assertSee('R$ 300,00')->assertSee('R$ 500,00'); // 60% de 2 × 250,00
        $this->actingAs($admin)->post(route('closings.store', $this->doctor), ['period' => '2026-10', 'settle' => 1])->assertRedirect();

        $closing = $this->tenant(fn () => DoctorClosing::query()->sole());
        $this->assertSame(['closed', 1, 30000, 30000], [$closing->status, $closing->version, (int) $closing->doctor_share_cents, (int) $closing->to_pay_cents]);
        $this->assertSame([2, 1, 1], [$closing->data['appointments']['attended'], $closing->data['appointments']['no_show'], $closing->data['appointments']['total'] - 2]);
        $this->assertTrue($closing->intact());
        $payable = $this->tenant(fn () => Payable::query()->findOrFail($closing->payable_id));
        $this->assertSame(30000, $payable->amount_cents);
        $this->assertSame(0, $this->tenant(fn () => PaymentSplit::query()->where('status', 'pending')->count()));

        // Não fecha de novo enquanto não for contestado.
        $this->actingAs($admin)->post(route('closings.store', $this->doctor), ['period' => '2026-10'])->assertSessionHas('error', fn ($m) => str_contains($m, 'já foi fechado'));

        // O médico vê só os seus; outro médico não acessa.
        $this->actingAs($doctorUser)->get(route('closings.mine'))->assertOk()->assertSee('outubro/2026');
        $this->actingAs($doctorUser)->get(route('closings.index'))->assertForbidden();
        $other = $this->userWithRole($this->company(), 'medico');
        $this->actingAs($other)->get(route('closings.show', $closing))->assertForbidden();
        $this->actingAs($other)->post(route('closings.respond', $closing), ['action' => 'confirm'])->assertForbidden();
        $this->assertStringStartsWith('%PDF', $this->actingAs($doctorUser)->get(route('closings.pdf', $closing))->assertOk()->getContent());

        // Contestação exige motivo → refazer gera v2 e a v1 fica substituída.
        $this->actingAs($doctorUser)->post(route('closings.respond', $closing), ['action' => 'dispute'])->assertSessionHas('error');
        $this->actingAs($doctorUser)->post(route('closings.respond', $closing), ['action' => 'dispute', 'notes' => 'Faltou o atendimento do dia 05 às 10h.'])->assertSessionHas('success');
        $this->actingAs($admin)->post(route('closings.store', $this->doctor), ['period' => '2026-10', 'settle' => 1])->assertRedirect();
        $versions = $this->tenant(fn () => DoctorClosing::query()->orderBy('version')->get());
        $this->assertSame([['superseded', 1], ['closed', 2]], $versions->map(fn ($c) => [$c->status, $c->version])->all());
        $this->assertSame([0, null], [(int) $versions[1]->to_pay_cents, $versions[1]->payable_id]); // repasse já lançado na v1

        $this->actingAs($doctorUser)->post(route('closings.respond', $versions[1]), ['action' => 'confirm'])->assertSessionHas('success');
        $this->assertSame('confirmed', $versions[1]->fresh()->status);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'closing.confirmed')->exists());

        // Adulteração direta no banco é detectada.
        DB::table('doctor_closings')->where('id', $versions[1]->id)->update(['data' => json_encode(['period' => '2026-10', 'split' => ['share' => 1]])]);
        $this->assertFalse($this->tenant(fn () => DoctorClosing::query()->findOrFail($versions[1]->id))->intact());
    }
}
