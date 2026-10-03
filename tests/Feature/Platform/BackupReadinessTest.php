<?php

namespace Tests\Feature\Platform;

use App\Core\Health\ReadinessChecker;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Services\BackupService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\Feature\Scheduling\SchedulingSetup;
use Tests\TestCase;

/** Fases 19–20 — prontidão (preflight) e backup: geração, criptografia, restauração real e painel. */
class BackupReadinessTest extends TestCase
{
    use SchedulingSetup;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/aivexa-backup-test-'.uniqid();
        File::ensureDirectoryExists($this->storage.'/app/companies/x');
        File::put($this->storage.'/app/companies/x/laudo.pdf', '%PDF-1.4 teste');
        $this->app->useStoragePath($this->storage);
        $this->setUpScheduling();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function superAdmin(): User
    {
        return $this->context()->runAsSystem(function () {
            $u = new User(['name' => 'Root', 'email' => 'root@example.test', 'password' => self::PASSWORD]);
            $u->is_super_admin = true;
            $u->save();

            return $u;
        });
    }

    public function test_database_backup_restores_into_an_empty_database(): void
    {
        $this->patient('Paciente Restaurável', ['notes' => "aspas ' e \\ barra; quebra\nlinha 😀"]);
        if (DB::getDriverName() !== 'mysql') {
            $r = app(BackupService::class)->database(); // pg_dump (processo externo: vê o esquema)
            $this->assertGreaterThan(50, $r['tables']);
            $this->assertStringContainsString('CREATE TABLE', (string) gzdecode((string) file_get_contents($r['path'])));

            return;
        }
        $r = app(BackupService::class)->database();
        $sql = (string) gzdecode((string) file_get_contents($r['path']));
        $this->assertStringContainsString('INSERT INTO `patients`', $sql);
        $this->assertSame(count(DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")), $r['tables']);

        // Restauração real com o cliente mysql em um banco vazio (o mesmo procedimento do runbook).
        $c = DB::connection()->getConfig();
        $target = (string) env('BACKUP_RESTORE_DB', 'aivexa_restore');
        $cli = sprintf('mysql -h %s -P %s -u %s %s', escapeshellarg((string) $c['host']), escapeshellarg((string) $c['port']), escapeshellarg((string) $c['username']), escapeshellarg($target));
        $env = ['MYSQL_PWD' => (string) $c['password'], 'PATH' => getenv('PATH')];
        $probe = proc_open($cli.' -e "SELECT 1"', [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (! is_resource($probe) || proc_close($probe) !== 0) {
            $this->markTestSkipped("Cliente mysql ou banco {$target} indisponível para o teste de restauração.");
        }
        $proc = proc_open($cli, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        fwrite($pipes[0], $sql);
        fclose($pipes[0]);
        $err = stream_get_contents($pipes[2]);
        $this->assertSame(0, proc_close($proc), "Falha ao restaurar: {$err}");

        DB::purge('restore');
        config(['database.connections.restore' => array_merge($c, ['database' => $target])]);
        $restored = DB::connection('restore');
        foreach (['companies', 'users', 'patients', 'roles', 'audit_logs', 'appointments', 'migrations'] as $t) {
            $expected = DB::table($t)->count() - ($t === 'audit_logs' ? 1 : 0); // o próprio backup gera 1 registro de auditoria depois do dump
            $this->assertSame($expected, $restored->table($t)->count(), "Contagem diferente em {$t}");
        }
        $original = (array) DB::table('patients')->orderByDesc('id')->first();
        $this->assertEquals($original, (array) $restored->table('patients')->where('id', $original['id'])->first());
        DB::purge('restore');
    }

    public function test_encrypted_backup_roundtrip_and_wrong_password(): void
    {
        config(['aivexa.backup.password' => 'Senha-Do-Cofre-123']);
        $service = app(BackupService::class);
        $files = $service->files();
        $this->assertStringEndsWith('.zip.enc', $files['path']);
        $this->assertSame(1, $files['files']);
        $this->assertStringStartsWith('AVXB1', (string) file_get_contents($files['path'], false, null, 0, 5));
        $this->assertStringNotContainsString('%PDF-1.4 teste', (string) file_get_contents($files['path']));

        $out = $this->storage.'/restaurado.zip';
        $service->decrypt($files['path'], $out, 'Senha-Do-Cofre-123');
        $zip = new \ZipArchive;
        $zip->open($out);
        $this->assertSame('%PDF-1.4 teste', $zip->getFromName('companies/x/laudo.pdf'));
        $zip->close();

        $this->expectException(RuntimeException::class);
        $service->decrypt($files['path'], $this->storage.'/errado.zip', 'senha-errada');
    }

    public function test_retention_keeps_only_the_latest_backups(): void
    {
        config(['aivexa.backup.keep_files' => 2]);
        $service = app(BackupService::class);
        foreach (['20260101-000000', '20260102-000000', '20260103-000000'] as $i => $stamp) {
            touch($service->dir()."/aivexa-arquivos-{$stamp}.zip", time() - 1000 + $i);
        }
        $service->files();
        $this->assertCount(2, glob($service->dir().'/aivexa-arquivos-*'));
        $this->assertFileExists($service->dir().'/aivexa-arquivos-20260103-000000.zip');
    }

    public function test_backup_panel_is_platform_only_and_downloads_are_audited(): void
    {
        $super = $this->superAdmin();
        $this->actingAs($super)->post(route('platform.backups.store'), ['files' => 1])->assertSessionHas('success');
        $list = app(BackupService::class)->list();
        $this->assertCount(2, $list);
        $this->actingAs($super)->get(route('platform.backups.index'))->assertOk()->assertSee($list[0]['name'])->assertSee('não estão criptografados');
        $this->actingAs($super)->get(route('platform.backups.download', $list[0]['name']))->assertOk()->assertDownload($list[0]['name']);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'backup.downloaded')->exists());
        $this->actingAs($super)->get(route('platform.backups.download', 'aivexa-db-..-x.sql.gz'))->assertNotFound();
        $this->actingAs($super)->get('/plataforma/backups/..%2F..%2F.env')->assertNotFound();

        $this->actingAs($this->clinic['admin'])->get(route('platform.backups.index'))->assertForbidden();
        $this->actingAs($this->clinic['admin'])->get(route('platform.backups.download', $list[0]['name']))->assertForbidden();
    }

    public function test_preflight_reports_problems_and_readiness_page(): void
    {
        config(['aivexa.stage' => 'production', 'app.debug' => true, 'app.url' => 'http://clinica.test']);
        Cache::forget(ReadinessChecker::HEARTBEAT_KEY);
        $this->artisan('aivexa:preflight')->assertFailed();

        $items = app(ReadinessChecker::class)->run();
        $errors = collect($items)->where('level', 'error')->pluck('item')->all();
        $this->assertContains('Modo debug', $errors);
        $this->assertContains('HTTPS', $errors);

        $this->artisan('aivexa:heartbeat')->assertSuccessful();
        $cron = collect(app(ReadinessChecker::class)->run())->firstWhere('item', 'Cron (agendador)');
        $this->assertNotNull($cron);
        $this->assertSame('ok', $cron['level']);

        $super = $this->superAdmin();
        $this->actingAs($super)->get(route('platform.readiness'))->assertOk()->assertSee('Prontidão para homologação e produção')->assertSee('Modo debug');
        $this->actingAs($this->clinic['admin'])->get(route('platform.readiness'))->assertForbidden();
    }
}
