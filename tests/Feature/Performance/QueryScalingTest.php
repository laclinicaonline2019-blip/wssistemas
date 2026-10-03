<?php

namespace Tests\Feature\Performance;

use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Models\MessagingChannel;
use App\Modules\Scheduling\Services\BookingService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Scheduling\SchedulingSetup;
use Tests\TestCase;

/**
 * Fase 18 — desempenho: as telas principais não podem fazer consultas proporcionais à quantidade
 * de registros (N+1). Mede cada tela com poucos e com muitos dados e exige crescimento ~zero.
 */
class QueryScalingTest extends TestCase
{
    use SchedulingSetup;

    private MessagingChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScheduling(['end_time' => '20:00', 'slot_minutes' => 10]);
        $this->channel = $this->tenant(fn () => MessagingChannel::create(['provider' => 'mock', 'mode' => 'mock', 'name' => 'WA', 'verify_token' => 'x', 'credentials' => []]));
    }

    private function tenant(Closure $fn): mixed
    {
        return $this->context()->runFor($this->company()->id, $fn);
    }

    /** Cria N pacientes com agendamento, conta a receber e conversa. */
    private function grow(int $n, int $offset): void
    {
        $f = app(FinanceService::class);
        for ($i = 0; $i < $n; $i++) {
            $p = $this->patient('Volume '.($offset + $i), ['whatsapp' => '1199'.str_pad((string) ($offset + $i), 7, '0', STR_PAD_LEFT)]);
            $this->tenant(function () use ($p, $i, $offset, $f) {
                $start = CarbonImmutable::parse(self::MONDAY.' 08:00', 'America/Sao_Paulo')->addMinutes(10 * ($offset + $i));
                app(BookingService::class)->book($this->clinic['admin'], ['branch_id' => $this->branch()->id, 'doctor_id' => $this->doctor->id,
                    'patient_id' => $p->id, 'service_id' => $this->service->id, 'starts_at' => $start, 'is_overbook' => false]);
                $f->createReceivable($this->clinic['admin'], ['branch_id' => $this->branch()->id, 'category_id' => $f->defaultCategory('income', 'Consultas'), 'patient_id' => $p->id,
                    'doctor_id' => $this->doctor->id, 'description' => 'Consulta '.$p->name, 'amount_cents' => 25000, 'due_date' => self::MONDAY]);
                $t = MessageThread::create(['channel_id' => $this->channel->id, 'phone' => '5511'.($offset + $i), 'patient_id' => $p->id, 'last_inbound_at' => now(), 'last_message_at' => now()]);
                Message::create(['channel' => 'whatsapp', 'channel_id' => $this->channel->id, 'thread_id' => $t->id, 'patient_id' => $p->id, 'direction' => 'in', 'purpose' => 'inbound',
                    'recipient' => $t->phone, 'body' => 'Olá', 'status' => 'received']);
            });
        }
    }

    private function queriesFor(string $url): int
    {
        $this->app['auth']->forgetGuards();
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        $this->actingAs($this->clinic['admin'])->get($url)->assertOk();
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return $count;
    }

    public function test_main_pages_do_not_grow_queries_with_data_volume(): void
    {
        $pages = [
            'Dashboard' => route('home'),
            'Agenda do dia' => route('agenda.index', ['date' => self::MONDAY, 'branch_id' => $this->branch()->id]),
            'Pacientes' => route('patients.index'),
            'Contas a receber' => route('receivables.index'),
            'Conversas' => route('messaging.inbox'),
            'Relatório de agendamentos' => route('reports.show', ['appointments', 'from' => self::MONDAY, 'to' => self::MONDAY]),
            'Fila' => route('queue.index'),
        ];

        $this->grow(3, 0);
        $small = array_map(fn ($u) => $this->queriesFor($u), $pages);
        $this->grow(25, 3);
        $large = array_map(fn ($u) => $this->queriesFor($u), $pages);

        $report = [];
        foreach ($pages as $name => $url) {
            $report[] = sprintf('%-28s %3d → %3d consultas', $name, $small[$name], $large[$name]);
            $this->assertLessThanOrEqual($small[$name] + 3, $large[$name], "N+1 em {$name}: {$small[$name]} consultas com 3 registros e {$large[$name]} com 28.\n".implode("\n", $report));
            $this->assertLessThan(80, $large[$name], "Consultas demais em {$name}");
        }
        $this->assertSame(28, $this->tenant(fn () => Receivable::query()->count()));
    }
}
