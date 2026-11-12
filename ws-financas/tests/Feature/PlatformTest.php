<?php

namespace Tests\Feature;

use App\Core\Audit\AuditLogger;
use App\Models\CompanySetting;
use App\Models\Debt;
use App\Models\Entry;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BackupService;
use App\Services\FinanceService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Cadastro, login, 2FA, cabeçalhos, importação local, backup e auditoria. */
class PlatformTest extends TestCase
{
    public function test_cadastro_self_service_com_teste_gratis(): void
    {
        $this->post('/cadastro', ['person_type' => 'pf', 'name' => 'Maria Silva', 'admin_name' => 'Maria', 'email' => 'maria@t.local', 'password' => 'Senha@Forte123',
            'password_confirmation' => 'Senha@Forte123', 'plan' => 'basico', 'cycle' => 'monthly', 'accept' => '1'])->assertRedirect('/app');
        $this->get('/app')->assertOk()->assertSee('WS Finanças em Dia');
        $this->assertSame('trialing', Subscription::query()->value('status'));
    }

    public function test_login_bloqueio_e_mensagem_neutra(): void
    {
        [, $u] = $this->makeClient();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/entrar', ['email' => $u->email, 'password' => 'errada'])->assertSessionHas('error', 'E-mail ou senha inválidos.');
        }
        $this->post('/entrar', ['email' => $u->email, 'password' => 'Senha@Forte123'])->assertSessionHas('error');
        $this->assertGuest();
        $this->post('/entrar', ['email' => 'naoexiste@t.local', 'password' => 'x'])->assertSessionHas('error', 'E-mail ou senha inválidos.');
    }

    public function test_2fa_obrigatorio_para_administradores(): void
    {
        config(['wsf.security.platform_admin_2fa' => true, 'wsf.security.account_admin_2fa' => true]);
        $owner = User::query()->create(['name' => 'Dono', 'email' => 'dono@t.local', 'password' => 'Senha@Forte123', 'is_platform_admin' => true]);
        $this->actingAs($owner)->get('/admin')->assertRedirect(route('security.2fa'));
        [, $admin] = $this->makeClient();
        $this->actingAs($admin)->get('/app')->assertRedirect(route('security.2fa'));
        $this->actingAs($admin)->get('/seguranca/2fa')->assertOk()->assertSee('<svg', false);
    }

    public function test_cabecalhos_de_seguranca_e_escape_xss(): void
    {
        [$a, $u, [$c]] = $this->makeClient();
        $r = $this->actingAs($u)->get('/app');
        $r->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("script-src 'self'", $r->headers->get('Content-Security-Policy'));
        $this->actingAs($u)->post('/app/empresas', ['name' => '<script>alert(1)</script>', 'person_type' => 'pj']);
        $this->actingAs($u)->get('/app/empresas')->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_importacao_dos_dados_locais_sem_duplicar_e_sem_apagar(): void
    {
        [$a, $u, [$c]] = $this->makeClient();
        $local = json_encode([
            ['id' => 'd1', 'tipo' => 'despesa', 'desc' => 'Aluguel', 'valor' => 1100, 'mes' => '2026-10', 'venc' => '2026-10-05', 'pago' => true, 'pagoEm' => '2026-10-04', 'cat' => 'Moradia', 'grupo' => 'g1'],
            ['id' => 'd2', 'tipo' => 'despesa', 'desc' => 'Aluguel', 'valor' => 1100, 'mes' => '2026-11', 'venc' => '2026-11-05', 'pago' => false, 'cat' => 'Moradia', 'grupo' => 'g1'],
            ['id' => 'r1', 'tipo' => 'receita', 'desc' => 'Salário', 'valor' => 3200, 'mes' => '2026-11', 'data' => '2026-11-05'],
            ['id' => 'v1', 'tipo' => 'divida', 'credor' => 'Cartão', 'saldo' => 4800, 'juros' => 12, 'parcela' => 480, 'criado' => 1],
            ['id' => 'x1', 'tipo' => 'despesa', 'desc' => 'Netflix', 'valor' => 44.9, 'mes' => '2026-11', 'venc' => '2026-11-12', 'exemplo' => true],
            ['id' => 'config', 'tipo' => 'config', 'orc' => 1200, 'estrategia' => 'bola'],
        ]);
        $this->actingAs($u)->post('/app/importar', ['empresa' => $c->id, 'conteudo' => $local])->assertSessionHas('ok');
        $this->actingAs($u)->post('/app/importar', ['empresa' => $c->id, 'conteudo' => $local])->assertSessionHas('ok');
        $this->inAccount($a, function () {
            $this->assertSame(3, Entry::query()->count());
            $this->assertSame(1, Entry::query()->whereNotNull('recurrence_group')->distinct()->count('recurrence_group'));
            $this->assertSame('2026-10-04', Entry::query()->where('external_ref', 'local:d1')->first()->paid_at->toDateString());
            $this->assertSame(1, Debt::query()->count());
            $this->assertSame('bola', CompanySetting::query()->value('debt_strategy'));
        });
    }

    public function test_backup_criptografado_e_restauracao_verificada(): void
    {
        [$a, , [$c]] = $this->makeClient();
        $this->inAccount($a, fn () => app(FinanceService::class)->loadSample($c));
        $svc = app(BackupService::class);
        $r = $this->ctx()->runAsSystem(fn () => $svc->create());
        $this->assertStringStartsWith('WSFB1', file_get_contents($r['file']));
        $this->assertStringNotContainsString('Aluguel', file_get_contents($r['file']));
        $v = $this->ctx()->runAsSystem(fn () => $svc->verify($r['file']));
        $this->assertTrue($v['ok'], implode('; ', $v['problems']));
        $this->assertSame($r['counts']['entries'], $v['counts']['entries']);
        @unlink($r['file']);
    }

    public function test_auditoria_detecta_adulteracao(): void
    {
        [$a, $u, [$c]] = $this->makeClient();
        $this->actingAs($u)->postJson('/app/api/lancamentos', ['empresa' => $c->id, 'tipo' => 'despesa', 'desc' => 'Luz', 'valor' => 100, 'data' => '2026-11-01'])->assertCreated();
        $this->assertTrue(AuditLogger::verify()['ok']);
        DB::table('audit_logs')->where('action', 'lancamento.criar')->update(['data' => json_encode(['valor' => 1])]);
        $this->assertFalse(AuditLogger::verify()['ok']);
    }

    public function test_relatorios_e_fluxo(): void
    {
        [$a, $u, [$c]] = $this->makeClient();
        $this->inAccount($a, fn () => app(FinanceService::class)->loadSample($c));
        $m = now()->format('Y-m');
        $this->actingAs($u)->getJson("/app/api/fluxo?empresa={$c->id}&mes=$m")->assertOk()->assertJsonCount(12, 'series');
        $this->actingAs($u)->getJson("/app/api/relatorio?empresa={$c->id}&de=$m&ate=$m")->assertOk()->assertJsonPath('entradas', 3600);
        $csv = $this->actingAs($u)->get("/app/relatorios/exportar?empresa={$c->id}&de=$m&ate=$m&formato=csv");
        $csv->assertOk();
        $this->assertStringContainsString('Aluguel', $csv->streamedContent());
        $pdf = $this->actingAs($u)->get("/app/relatorios/exportar?empresa={$c->id}&de=$m&ate=$m&formato=pdf");
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_paginas_principais_carregam(): void
    {
        foreach (['/', '/privacidade', '/termos', '/entrar', '/cadastro', '/esqueci-senha'] as $url) {
            $this->get($url)->assertOk();
        }
        [$a, $u] = $this->makeClient();
        foreach (['/app', '/app/empresas', '/app/usuarios', '/app/whatsapp', '/app/importar', '/app/assinatura', '/app/privacidade', '/app/auditoria', '/seguranca'] as $url) {
            $this->actingAs($u)->get($url)->assertOk();
        }
        $owner = User::query()->create(['name' => 'Dono', 'email' => 'dono@t.local', 'password' => 'Senha@Forte123', 'is_platform_admin' => true]);
        foreach (['/admin', '/admin/clientes', '/admin/planos', '/admin/faturas', '/admin/uso', '/admin/sistema'] as $url) {
            $this->actingAs($owner)->get($url)->assertOk();
        }
    }
}
