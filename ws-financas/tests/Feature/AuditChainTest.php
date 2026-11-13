<?php

namespace Tests\Feature;

use App\Core\Audit\AuditAnchor;
use App\Core\Audit\AuditLogger;
use App\Core\Audit\ChainVerifier;
use App\Core\Support\BusinessRule;
use App\Models\AuditLog;
use App\Models\Entry;
use App\Models\User;
use App\Services\BackupService;
use App\Services\PrivacyService;
use App\Services\Update\UpdateService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Auditoria 1.1.1: compatibilidade v1 (inclusive o estado real da homologação: #4 backup.criar e
 * #5 backup.teste_restauracao), v2 canônico, transição, adulteração, exclusão, bifurcação, rebaixamento,
 * chave trocada, limites, âncora e recuperação sem perda. Roda em SQLite, MariaDB, MySQL 8 e Percona 5.7.
 */
class AuditChainTest extends TestCase
{
    private string $storage;

    private int $base = 1;

    /** Id do n-ésimo registro da cena (no MySQL o AUTO_INCREMENT não volta com o rollback entre testes). */
    private function n(int $pos): int
    {
        return $this->base + $pos - 1;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/wsf-audit-test-'.uniqid();
        File::ensureDirectoryExists($this->storage.'/app');
        $this->app->useStoragePath($this->storage);
        config(['wsf.security.audit_hmac_key' => 'chave-de-teste-da-auditoria-0123456789abcdef', 'wsf.audit.anchor_email' => false]);
        AuditLogger::resetHeadCache();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function mysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    /** Grava um registro v1 exatamente como a 1.1.0 (hash na ordem original), com o JSON armazenado na ordem que o banco devolveria. */
    private function insertV1(string $action, ?array $data, ?string $storedJson = null, string $createdAt = '2026-11-10 10:00:00'): int
    {
        $head = DB::table('audit_chain_head')->where('id', 1)->first();
        $row = ['account_id' => null, 'user_id' => null, 'action' => $action, 'subject_type' => null, 'subject_id' => null, 'data' => $data,
            'ip' => null, 'channel' => 'sistema', 'created_at' => $createdAt, 'prev_hash' => (int) $head->last_id === 0 ? null : $head->last_hash];
        $row['hash'] = AuditLogger::hashOf($row);
        $row['data'] = $data === null ? null : ($storedJson ?? json_encode($data));
        $id = DB::table('audit_logs')->insertGetId($row);
        DB::table('audit_chain_head')->where('id', 1)->update(['last_id' => $id, 'last_hash' => $row['hash']]);   // no MySQL o trigger já fez

        return $id;
    }

    /** Reproduz a homologação: 9 registros v1, #4 backup.criar e #5 backup.teste_restauracao com as chaves reordenadas pelo MySQL. */
    private function homologacao(): void
    {
        $this->base = $this->insertV1('assinatura.teste_iniciado', ['plano' => 'empresarial', 'dias' => 14]);
        $this->insertV1('usuario.login', null);
        $this->insertV1('lancamento.criar', ['tipo' => 'despesa', 'desc' => 'Luz', 'valor' => 100, 'repetir' => 'nao', 'modo' => 'manual', 'origem' => 'web'],
            '{"desc":"Luz","modo":"manual","tipo":"despesa","valor":100,"origem":"web","repetir":"nao"}');
        $this->insertV1('backup.criar', ['arquivo' => 'wsf-20261110-100000.wsfbak', 'bytes' => 48213],
            '{"bytes":48213,"arquivo":"wsf-20261110-100000.wsfbak"}');
        $this->insertV1('backup.teste_restauracao', ['arquivo' => 'wsf-20261110-100000.wsfbak', 'ok' => true, 'problemas' => []],
            '{"ok":true,"arquivo":"wsf-20261110-100000.wsfbak","problemas":[]}');
        $this->insertV1('usuario.login', null);
        $this->insertV1('divida.salvar', ['credor' => 'Banco', 'saldo' => 1500.5], '{"saldo":1500.5,"credor":"Banco"}');
        $this->insertV1('relatorio.exportar', ['formato' => 'csv', 'de' => '2026-10', 'ate' => '2026-11', 'empresas' => [1, 2]],
            '{"de":"2026-10","ate":"2026-11","formato":"csv","empresas":[1,2]}');
        $this->insertV1('usuario.logout', null);
    }

    private function verify(): array
    {
        return app(ChainVerifier::class)->run(null, null, false);
    }

    /** DDL no MySQL encerra a transação do teste: reabre e força recriar o banco no próximo teste. */
    private function ddl(callable $fn): void
    {
        $fn();
        if ($this->mysql()) {
            RefreshDatabaseState::$migrated = false;
            if (! DB::getPdo()->inTransaction()) {
                DB::getPdo()->beginTransaction();
            }
        }
    }

    private function recreateProtections(): void
    {
        $this->ddl(fn () => (require database_path('migrations/2026_11_14_000100_audit_v2.php'))->up());
    }

    // ================================================================== compatibilidade v1

    public function test_homologacao_9_registros_v1_verificam_inclusive_4_e_5(): void
    {
        $this->homologacao();
        $r = $this->verify();
        $this->assertTrue($r['ok'], json_encode($r['problems']));
        $this->assertSame(9, $r['checked']);
        $compat = collect($r['compat'])->keyBy('id');
        $this->assertSame('backup.criar', $compat[$this->n(4)]['action']);
        $this->assertStringContainsString('arquivo, bytes', $compat[$this->n(4)]['method']);
        $this->assertSame('backup.teste_restauracao', $compat[$this->n(5)]['action']);
        $this->assertStringContainsString('arquivo, ok, problemas', $compat[$this->n(5)]['method']);
        // registros sem data ou já na ordem lida: íntegros sem compatibilidade
        // o total por status depende do banco (MySQL reordena também o #1; SQLite/MariaDB guardam o texto como veio)
        $this->assertSame(9, ($r['summary']['integro_v1'] ?? 0) + ($r['summary']['integro_v1_compat'] ?? 0));
    }

    public function test_compatibilidade_nunca_aceita_conteudo_diferente(): void
    {
        $this->insertV1('backup.criar', ['arquivo' => 'a.wsfbak', 'bytes' => 10], '{"bytes":11,"arquivo":"a.wsfbak"}');   // valor diferente
        $r = $this->verify();
        $this->assertFalse($r['ok']);
        $this->assertSame('hmac_invalido', $r['problems'][0]['status']);
    }

    public function test_limite_de_tentativas_resulta_nao_verificavel(): void
    {
        config(['wsf.audit.max_attempts_per_record' => 5]);
        $this->insertV1('x.teste', ['e' => 1, 'd' => 2, 'c' => 3, 'b' => 4, 'a' => 5], '{"a":5,"b":4,"c":3,"d":2,"e":1}');
        $r = $this->verify();
        $this->assertSame('nao_verificavel', $r['problems'][0]['status']);

        config(['wsf.audit.max_attempts_per_record' => 50000]);
        $this->assertTrue($this->verify()['ok']);     // com o limite padrão, a permutação é encontrada
    }

    // ================================================================== v2 e transição

    public function test_transicao_v1_para_v2_com_dados_variados(): void
    {
        $this->homologacao();
        $logger = app(AuditLogger::class);
        $logger->log('antes.da.ativacao', null, ['b' => 1, 'a' => 2], channel: 'sistema');
        $logger->activateV2(null);
        $head = DB::table('audit_chain_head')->where('id', 1)->first();
        $this->assertSame(2, (int) $head->write_version);
        $this->assertSame(AuditLogger::keyId(), $head->key_id);
        $this->assertSame($this->n(11), (int) $head->activated_v2_from_id);

        $logger->log('lancamento.criar', null, ['desc' => 'Água ção 😀', 'valor' => 0.1 + 0.2, 'grande' => PHP_INT_MAX, 'vazio' => [], 'obj' => new \stdClass,
            'lista' => [3, 1, 2], 'num' => [1 => 'b', 0 => 'a'], 'neg0' => -0.0, 'bin' => "\xff\x01", 'nested' => ['z' => ['y' => 1e21, 'x' => 5e-324]]], channel: 'web');
        $logger->log('whatsapp.mensagem', null, ['de' => 'contato', 'texto' => "linha1\nlinha2\t\u{2028}"], channel: 'whatsapp');
        $logger->log('cron.lembretes', null, [], channel: 'sistema');

        $rows = AuditLog::query()->orderBy('id')->get();
        $this->assertSame(14, $rows->count());
        $this->assertNull($rows[9]->hash_version);               // #10 gravado antes da ativação: v1
        $this->assertSame(2, $rows[10]->hash_version);          // #11 = evento de ativação
        foreach ($rows->slice(10) as $l) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $l->created_at_utc);
        }
        $this->assertCount(4, $rows->slice(10)->pluck('created_at_utc')->unique());   // microssegundos distinguem
        $r = $this->verify();
        $this->assertTrue($r['ok'], json_encode($r['problems']));
        $this->assertSame(4, $r['summary']['integro_v2']);
    }

    public function test_ativacao_exige_chave_propria_e_e_unica(): void
    {
        config(['wsf.security.audit_hmac_key' => '']);
        $this->expectException(BusinessRule::class);
        app(AuditLogger::class)->activateV2(null);
    }

    public function test_ativacao_nao_repete(): void
    {
        app(AuditLogger::class)->activateV2(null);
        $this->expectException(BusinessRule::class);
        app(AuditLogger::class)->activateV2(null);
    }

    public function test_chave_trocada_bloqueia_gravacao_e_aparece_na_verificacao(): void
    {
        $logger = app(AuditLogger::class);
        $logger->activateV2(null);
        $logger->log('a', null, ['x' => 1], channel: 'sistema');
        config(['wsf.security.audit_hmac_key' => 'outra-chave-qualquer-0000000000000000']);
        $r = $this->verify();
        $this->assertSame('chave_diferente', $r['problems'][0]['status']);
        $this->expectException(BusinessRule::class);
        $logger->log('b', null, [], channel: 'sistema');
    }

    // ================================================================== proteções e detecção

    public function test_banco_recusa_update_e_delete_mysql(): void
    {
        if (! $this->mysql()) {
            $this->markTestSkipped('triggers só no MySQL/MariaDB');
        }
        $this->homologacao();
        foreach ([fn () => DB::table('audit_logs')->where('id', $this->n(4))->update(['action' => 'x']), fn () => DB::table('audit_logs')->where('id', $this->n(9))->delete(),
            fn () => DB::table('audit_chain_head')->where('id', 1)->update(['last_id' => $this->n(3)]), fn () => DB::table('audit_chain_head')->delete()] as $op) {
            try {
                $op();
                $this->fail('operação deveria ser recusada');
            } catch (QueryException $e) {
                $this->assertStringContainsString('45000', $e->getMessage());
            }
        }
        $this->assertTrue($this->verify()['ok']);
    }

    public function test_banco_recusa_bifurcacao_e_rebaixamento_mysql(): void
    {
        if (! $this->mysql()) {
            $this->markTestSkipped('triggers só no MySQL/MariaDB');
        }
        $this->homologacao();
        $h3 = DB::table('audit_logs')->where('id', $this->n(3))->value('hash');
        try {   // insere apontando para um registro antigo
            DB::table('audit_logs')->insert(['action' => 'fork', 'channel' => 'web', 'created_at' => '2026-11-10 10:00:00', 'prev_hash' => $h3, 'hash' => str_repeat('a', 64)]);
            $this->fail('bifurcação aceita');
        } catch (QueryException $e) {
            $this->assertMatchesRegularExpression('/bifurcacao|Duplicate|1062/', $e->getMessage());
        }
        app(AuditLogger::class)->activateV2(null);
        $top = DB::table('audit_chain_head')->value('last_hash');
        try {   // processo antigo (1.1.0) tentando gravar v1 depois da ativação
            DB::table('audit_logs')->insert(['action' => 'v1', 'channel' => 'web', 'created_at' => '2026-11-10 10:00:00', 'prev_hash' => $top, 'hash' => str_repeat('b', 64)]);
            $this->fail('rebaixamento aceito');
        } catch (QueryException $e) {
            $this->assertStringContainsString('rebaixamento', $e->getMessage());
        }
        try {
            DB::table('audit_chain_head')->where('id', 1)->update(['write_version' => 1]);
            $this->fail('write_version diminuiu');
        } catch (QueryException $e) {
            $this->assertStringContainsString('write_version', $e->getMessage());
        }
    }

    public function test_adulteracao_detectada(): void
    {
        $this->homologacao();
        app(AuditLogger::class)->activateV2(null);
        app(AuditLogger::class)->log('lancamento.criar', null, ['valor' => 100], channel: 'web');
        $this->assertTrue($this->verify()['ok']);

        // atacante com acesso total ao banco (remove os triggers) altera um v1 e um v2
        $this->ddl(function () {
            if ($this->mysql()) {
                DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
            }
        });
        DB::table('audit_logs')->where('id', $this->n(4))->update(['data' => '{"bytes":1,"arquivo":"wsf-20261110-100000.wsfbak"}']);
        DB::table('audit_logs')->where('id', $this->n(11))->update(['data' => '{"valor":1}']);
        $r = $this->verify();
        $this->assertFalse($r['ok']);
        $this->assertSame([$this->n(4) => 'hmac_invalido', $this->n(11) => 'hmac_invalido'], collect($r['problems'])->pluck('status', 'id')->all());
        $this->recreateProtections();
    }

    public function test_exclusao_no_meio_no_final_e_ancora(): void
    {
        $this->homologacao();
        app(AuditAnchor::class)->create();                    // âncora em #9
        $this->ddl(function () {
            if ($this->mysql()) {
                DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');
                DB::unprepared('DROP TRIGGER IF EXISTS audit_head_guard');
            }
        });
        DB::table('audit_logs')->where('id', $this->n(6))->delete();
        $r = $this->verify();
        $this->assertSame('ligacao_quebrada', collect($r['problems'])->firstWhere('id', $this->n(7))['status']);
        $this->assertSame('exclusao_do_final', collect($r['problems'])->firstWhere('status', 'exclusao_do_final')['status']);   // total menor que o ancorado

        // exclusão do final, com o topo "consertado" pelo atacante: só a âncora denuncia
        DB::table('audit_logs')->where('id', '>=', $this->n(5))->delete();
        DB::table('audit_chain_head')->where('id', 1)->update(['last_id' => $this->n(4), 'last_hash' => DB::table('audit_logs')->where('id', $this->n(4))->value('hash')]);
        $r = $this->verify();
        $this->assertSame(['exclusao_do_final'], collect($r['problems'])->pluck('status')->all());
        $anchorEmail = json_encode(app(AuditAnchor::class)->latestValid());
        $this->assertStringContainsString('ATENÇÃO', app(AuditAnchor::class)->checkExternal($anchorEmail)['message']);
        $this->recreateProtections();
    }

    public function test_topo_divergente_quando_o_final_e_apagado(): void
    {
        $this->homologacao();
        $this->ddl(function () {
            if ($this->mysql()) {
                DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');
            }
        });
        DB::table('audit_logs')->where('id', $this->n(9))->delete();
        $this->assertSame('topo_divergente', $this->verify()['problems'][0]['status']);
        $this->recreateProtections();
    }

    public function test_bifurcacao_e_rebaixamento_detectados_mesmo_sem_protecoes(): void
    {
        $this->homologacao();
        app(AuditLogger::class)->activateV2(null);                                   // #10 (v2)
        $this->ddl(function () {
            if ($this->mysql()) {
                foreach (['audit_logs_chain_guard', 'audit_logs_advance_head'] as $t) {
                    DB::unprepared("DROP TRIGGER IF EXISTS {$t}");
                }
                DB::statement('ALTER TABLE audit_logs DROP INDEX audit_logs_prev_hash_unique');
            } else {
                DB::statement('DROP INDEX audit_logs_prev_hash_unique');
            }
        });
        $h10 = DB::table('audit_logs')->where('id', $this->n(10))->value('hash');
        // rebaixamento: v1 gravado depois da ativação
        $row = ['action' => 'v1.apos.v2', 'channel' => 'web', 'created_at' => '2026-11-10 10:00:00', 'prev_hash' => $h10, 'data' => null];
        $row['hash'] = AuditLogger::hashOf($row);
        DB::table('audit_logs')->insert($row);
        // bifurcação: outro registro apontando para #3
        $fork = ['action' => 'fork', 'channel' => 'web', 'created_at' => '2026-11-10 10:00:00', 'prev_hash' => DB::table('audit_logs')->where('id', $this->n(3))->value('hash'), 'data' => null];
        $fork['hash'] = AuditLogger::hashOf($fork);
        DB::table('audit_logs')->insert($fork);

        $st = collect($this->verify()['problems'])->reject(fn ($p) => $p['status'] === 'topo_divergente')->pluck('status', 'id')->all();
        $this->assertSame('rebaixamento', $st[$this->n(11)]);
        $this->assertSame('bifurcacao', $st[$this->n(12)]);
        if ($this->mysql()) {
            RefreshDatabaseState::$migrated = false;   // índice removido: o próximo teste recria o banco
        }
    }

    // ================================================================== verificação em lotes, relatório e âncora

    public function test_verificacao_em_lotes_retoma_e_gera_relatorio_assinado(): void
    {
        $this->homologacao();
        $v = app(ChainVerifier::class);
        $r = $v->run(0.0, 2);                    // limite de tempo zero: um lote por chamada
        $this->assertFalse($r['done']);
        $this->assertSame(2, $r['checked']);
        $this->assertNotNull($v->pendingState());
        do {
            $r = $v->run(0.0, 2, true, true);
        } while (! $r['done']);
        $this->assertTrue($r['ok']);
        $this->assertSame(9, $r['checked']);
        $rep = $v->latestReport();
        $this->assertSame(9, $rep['registros']);
        $sig = $rep['assinatura'];
        unset($rep['assinatura'], $rep['arquivo']);
        $this->assertSame($sig, hash_hmac('sha256', json_encode($rep, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), AuditLogger::legacyKey()));
        $this->assertSame('auditoria.verificacao', AuditLog::query()->orderByDesc('id')->value('action'));   // evento-resumo na cadeia
        $this->assertTrue($this->verify()['ok']);
    }

    public function test_ancora_por_email_e_conferencia(): void
    {
        Mail::fake();
        config(['wsf.audit.anchor_email' => true, 'wsf.audit.anchor_recipients' => 'dono@teste.local']);
        $this->homologacao();
        $r = app(AuditAnchor::class)->create();
        $this->assertSame(['dono@teste.local'], $r['emailed']);
        $this->assertSame($this->n(9), $r['anchor']['last_id']);
        $ok = app(AuditAnchor::class)->checkExternal(json_encode($r['anchor']));
        $this->assertTrue($ok['valid_signature'] && $ok['present'] && $ok['total_ok']);
        $forged = $r['anchor'];
        $forged['total'] = 3;
        $this->assertFalse(app(AuditAnchor::class)->checkExternal(json_encode($forged))['valid_signature']);
    }

    public function test_painel_do_proprietario(): void
    {
        $this->homologacao();
        $admin = User::query()->create(['name' => 'Dono', 'email' => 'dono@teste.local', 'password' => 'Senha@Forte123', 'is_platform_admin' => true, 'status' => 'active']);
        $this->actingAs($admin)->get('/admin/sistema')->assertOk()->assertSee('Verificar auditoria')->assertSee('Ativar formato v2');
        $this->actingAs($admin)->post('/admin/sistema/auditoria/verificar')->assertSessionHas('ok');
        $this->actingAs($admin)->get('/admin/sistema')->assertOk()->assertSee('íntegra')->assertSee('por compatibilidade');
        $this->actingAs($admin)->post('/admin/sistema/auditoria/ativar-v2', ['password' => 'errada', 'confirm' => 'ATIVAR V2'])->assertSessionHasErrors('password');
        $this->actingAs($admin)->post('/admin/sistema/auditoria/ativar-v2', ['password' => 'Senha@Forte123', 'confirm' => 'ATIVAR V2'])->assertSessionHas('ok');
        $this->assertSame(2, (int) DB::table('audit_chain_head')->value('write_version'));
        $this->actingAs($admin)->post('/admin/sistema/auditoria/ancora')->assertSessionHas('ok');
        $a = json_encode(app(AuditAnchor::class)->latestValid());
        $this->actingAs($admin)->post('/admin/sistema/auditoria/conferir-ancora', ['ancora' => "-----BEGIN WSF ANCORA-----\n$a\n-----END WSF ANCORA-----"])->assertSessionHas('ok');
        $this->assertTrue($this->verify()['ok']);
    }

    // ================================================================== recuperação sem perda

    public function test_restauracao_sem_perda_nao_apaga_nem_sobrescreve(): void
    {
        [$account, $user, [$company]] = $this->makeClient();
        $this->homologacao();
        $backups = app(BackupService::class);
        $b = $backups->create();
        $countBefore = AuditLog::query()->count();

        // depois do backup: novos dados e nova auditoria
        $this->actingAs($user)->postJson('/app/api/lancamentos', ['empresa' => $company->id, 'tipo' => 'despesa', 'desc' => 'Depois', 'valor' => 5, 'data' => '2026-11-01'])->assertCreated();
        $after = AuditLog::query()->count();
        $this->assertGreaterThan($countBefore, $after);

        $rep = $backups->restore($b['file']);
        $this->assertSame($after, AuditLog::query()->count());                       // nada apagado
        $this->assertSame(0, $rep['audit_logs']['inseridos']);
        $this->assertSame(0, $rep['audit_logs']['conflitos']);
        $this->assertSame(1, Entry::query()->withoutGlobalScopes()->where('description', 'Depois')->count());   // dado novo preservado
        $this->assertTrue($this->verify()['ok']);
    }

    public function test_restauracao_em_banco_vazio_reconstroi_cadeia_e_topo(): void
    {
        $this->homologacao();
        app(AuditLogger::class)->activateV2(null);
        app(AuditLogger::class)->log('x', null, ['a' => 1], channel: 'sistema');
        $hashes = AuditLog::query()->orderBy('id')->pluck('hash')->all();   // o evento backup.criar é gravado depois da cópia
        $b = app(BackupService::class)->create();

        // banco de destino vazio (SQLite em memória com as migrações)
        config(['database.connections.destino' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        $this->artisan('migrate', ['--database' => 'destino', '--force' => true]);
        app(BackupService::class)->restore($b['file'], 'destino');
        $this->assertSame($hashes, DB::connection('destino')->table('audit_logs')->orderBy('id')->pluck('hash')->all());
        $h = DB::connection('destino')->table('audit_chain_head')->first();
        $this->assertSame(2, (int) $h->write_version);
        $this->assertSame(end($hashes), $h->last_hash);
    }

    public function test_falha_na_migracao_iniciada_pela_110_remove_triggers_junto_com_o_topo(): void
    {
        if (! $this->mysql()) {
            $this->markTestSkipped('triggers só no MySQL/MariaDB');
        }
        $svc = app(UpdateService::class);
        $call = fn (string $m, ...$a) => (new \ReflectionMethod($svc, $m))->invoke($svc, ...$a);
        $tables = array_values(array_diff(array_map(fn ($t) => (string) $t['name'], Schema::getTables()), ['audit_chain_head']));
        $state = ['tables_before' => $tables];          // estado gravado pela 1.1.0: sem triggers_before
        $this->ddl(function () use ($call, $state) {
            $call('dropNewTriggers', $state);
            $call('dropNewTables', $state, true);
        });
        $this->assertSame([], array_values(array_intersect($call('triggers'), UpdateService::AUDIT_TRIGGERS)));
        $this->assertFalse(Schema::hasTable('audit_chain_head'));
        AuditLogger::resetHeadCache();
        app(AuditLogger::class)->log('apos.falha', null, ['ok' => 1], channel: 'sistema');   // gravação volta a funcionar (modo 1.1.0)
        $this->assertSame('apos.falha', AuditLog::query()->orderByDesc('id')->value('action'));

        // estado de uma atualização 1.1.1→1.1.2 (topo já existia): nada da auditoria é removido
        $this->recreateProtections();
        AuditLogger::resetHeadCache();
        $this->ddl(fn () => $call('dropNewTriggers', ['tables_before' => array_merge($tables, ['audit_chain_head'])]));
        $this->assertCount(6, array_intersect($call('triggers'), UpdateService::AUDIT_TRIGGERS));
    }

    public function test_retencao_lgpd_nao_apaga_auditoria(): void
    {
        $this->homologacao();
        $n = AuditLog::query()->count();
        $this->travel(10)->years();
        app(PrivacyService::class)->runRetention();
        $this->assertSame($n, AuditLog::query()->count());
    }
}
