<?php

namespace Tests\Feature\Scheduling;

use App\Core\Access\PermissionRegistry;
use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Core\Tenancy\TenantContext;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Services\CompanyProvisioningService;
use App\Modules\Scheduling\Models\ScheduleTemplate;
use App\Modules\Scheduling\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Concorrência REAL: processos paralelos (pcntl_fork), cada um com sua própria
 * conexão, disputando o mesmo horário/período. Os dados são gravados de fato
 * (sem a transação envolvente dos demais testes) e o banco é recriado ao final.
 */
class ConcurrentBookingTest extends BaseTestCase
{
    private const WORKERS = 8;

    private string $companyId;

    private string $branchId;

    private string $doctorId;

    /** @var list<string> */
    private array $patientIds = [];

    private string $resultsDir;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Extensão pcntl indisponível.');
        }

        Artisan::call('migrate:fresh', ['--force' => true]);
        AuditLogger::$baseTransactionLevel = 0;
        app(PermissionRegistry::class)->sync();

        $context = app(TenantContext::class);
        $clinic = $context->runAsSystem(fn () => app(CompanyProvisioningService::class)->provision(
            company: ['legal_name' => 'Concorrência Ltda', 'trade_name' => 'Concorrência', 'document' => '11222333000181', 'status' => 'active'],
            headquarters: ['name' => 'Matriz'],
            admin: ['name' => 'Admin', 'email' => 'admin@concorrencia.test', 'password' => 'Senha@Forte123'],
        ));

        $this->companyId = $clinic['company']->id;
        $this->branchId = $clinic['branch']->id;

        $context->runFor($this->companyId, function () {
            $doctor = Doctor::create(['name' => 'Dra. Concorrida', 'crm' => '1', 'crm_state' => 'SP']);
            $doctor->branches()->attach($this->branchId, ['company_id' => $this->companyId]);
            ScheduleTemplate::create(['doctor_id' => $doctor->id, 'branch_id' => $this->branchId, 'weekday' => 1,
                'start_time' => '08:00', 'end_time' => '12:00', 'slot_minutes' => 30, 'max_patients' => 2]);
            $this->doctorId = $doctor->id;

            for ($i = 0; $i < self::WORKERS; $i++) {
                $p = new Patient(['name' => "Paciente {$i}", 'birth_date' => '1990-01-01']);
                $p->record_number = $i + 1;
                $p->save();
                $this->patientIds[] = $p->id;
            }
        });

        $this->resultsDir = sys_get_temp_dir().'/aivexa-concurrency-'.getmypid();
        @mkdir($this->resultsDir);
    }

    protected function tearDown(): void
    {
        if (function_exists('pcntl_fork')) {
            array_map('unlink', glob($this->resultsDir.'/*') ?: []);
            @rmdir($this->resultsDir);
            Artisan::call('migrate:fresh', ['--force' => true]);
        }

        parent::tearDown();
    }

    public function test_only_one_of_many_simultaneous_requests_gets_the_same_slot(): void
    {
        $results = $this->race(fn (int $i) => CarbonImmutable::parse('2030-01-07 08:00', 'America/Sao_Paulo')); // segunda-feira

        $this->assertSame(1, $results['ok'], json_encode($results));
        $this->assertSame(self::WORKERS - 1, $results['rejected'], json_encode($results));
        $this->assertSame(1, DB::table('appointments')->count());
    }

    public function test_period_limit_holds_under_concurrency(): void
    {
        // Cada processo tenta um horário diferente do mesmo período (limite: 2 pacientes).
        $results = $this->race(fn (int $i) => CarbonImmutable::parse('2030-01-07 08:00', 'America/Sao_Paulo')->addMinutes(30 * $i));

        $this->assertSame(2, $results['ok'], json_encode($results));
        $this->assertSame(2, DB::table('appointments')->count());
    }

    /** @return array{ok: int, rejected: int, errors: list<string>} */
    private function race(callable $startFor): array
    {
        DB::disconnect();
        $pids = [];

        for ($i = 0; $i < self::WORKERS; $i++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                $this->child($i, $startFor($i));
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        DB::reconnect();
        $out = ['ok' => 0, 'rejected' => 0, 'errors' => []];

        foreach (glob($this->resultsDir.'/*') as $file) {
            $line = trim((string) file_get_contents($file));
            match (true) {
                $line === 'ok' => $out['ok']++,
                str_starts_with($line, 'rejected') => $out['rejected']++,
                default => $out['errors'][] = $line,
            };
        }

        $this->assertSame([], $out['errors']);

        return $out;
    }

    private function child(int $i, CarbonImmutable $start): never
    {
        try {
            DB::purge();
            DB::reconnect();
            usleep(random_int(0, 2000)); // embaralha levemente a ordem de chegada

            app(TenantContext::class)->runFor($this->companyId, fn () => app(BookingService::class)->book(null, [
                'doctor_id' => $this->doctorId, 'branch_id' => $this->branchId,
                'patient_id' => $this->patientIds[$i], 'starts_at' => $start,
            ]));
            $result = 'ok';
        } catch (BusinessRuleViolation $e) {
            $result = 'rejected '.$e->errorCode;
        } catch (Throwable $e) {
            $result = 'error '.get_class($e).': '.$e->getMessage();
        }

        file_put_contents("{$this->resultsDir}/{$i}", $result);
        // Encerra o processo filho sem executar o ciclo de vida do PHPUnit.
        posix_kill(getmypid(), SIGKILL);
        exit(0);
    }
}
