<?php

namespace Tests\Feature;

use App\Core\Audit\AuditAnchor;
use App\Core\Security\CredentialInEmail;
use App\Core\Security\SecretGuard;
use App\Models\User;
use App\Services\MailDelivery;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Nenhuma credencial do .env pode chegar a um e-mail (assunto, remetente, cabeçalhos, texto ou HTML).
 * Todas as credenciais aqui são FICTÍCIAS.
 */
class EmailCredentialSecurityTest extends TestCase
{
    private const SECRETS = [
        'mail.mailers.smtp.password' => 'Fict1cia-SMTP #senha',
        'wsf.backup.password' => 'Fict1cia-Backup-Senha',
        'wsf.security.audit_hmac_key' => 'fict1cia0chave0auditoria0hmac0123',
        'services.whatsapp.access_token' => 'EAAGfict1cio-token-whatsapp-0000',
        'services.whatsapp.app_secret' => 'f1c71c10a00b00c00d00e00f00a00b00',
        'services.anthropic.key' => 'sk-ant-fict1cia-chave-0000',
    ];

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/wsf-mailsec-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/app');
        $this->app->useStoragePath($this->dir);   // âncoras e relatórios do teste ficam numa pasta temporária
        config(self::SECRETS + ['mail.default' => 'array', 'mail.from.address' => 'nao-responda@wsfinancasemdia.com.br', 'mail.from.name' => 'WS Finanças em Dia',
            'wsf.audit.anchor_email' => true, 'wsf.audit.anchor_recipients' => 'dono@teste.local']);
        SecretGuard::protectDisplayConfig();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** @return list<string> mensagens completas (cabeçalhos + corpo), como sairiam para o servidor SMTP */
    private function sent(): array
    {
        return array_map(fn ($m) => $m->toString(), iterator_to_array(app('mailer')->getSymfonyTransport()->messages()));
    }

    private function assertNoSecrets(array $raws): void
    {
        $all = implode("\n", $raws);
        foreach (array_merge(array_values(self::SECRETS), [(string) config('app.key')]) as $secret) {
            foreach ([$secret, base64_encode($secret), quoted_printable_encode($secret), rawurlencode($secret)] as $form) {
                $this->assertStringNotContainsString($form, $all, 'credencial encontrada no e-mail');
            }
        }
    }

    private function owner(): User
    {
        return User::query()->create(['name' => 'Dono', 'email' => 'dono@teste.local', 'password' => 'Senha@Forte123', 'is_platform_admin' => true, 'status' => 'active']);
    }

    public function test_todos_os_emails_do_sistema_saem_sem_credenciais(): void
    {
        [, $u] = $this->makeClient('profissional');
        $owner = $this->owner();
        $this->post('/esqueci-senha', ['email' => $u->email])->assertRedirect();
        $this->actingAs($owner)->post('/admin/clientes', ['name' => 'Cli', 'person_type' => 'pf', 'email' => 'conv@teste.local', 'admin_name' => 'F',
            'plan' => 'profissional', 'cycle' => 'monthly', 'trial_days' => 14])->assertSessionHas('ok');
        $this->actingAs($owner)->post('/admin/sistema/email-teste')->assertSessionHas('ok');
        app(AuditAnchor::class)->create();

        $raws = $this->sent();
        $this->assertCount(4, $raws, 'recuperação, convite, teste e âncora');
        $this->assertNoSecrets($raws);
        foreach ($raws as $raw) {
            $headers = strstr($raw, "\r\n\r\n", true);
            $this->assertMatchesRegularExpression('/^From: [^\r\n]*(\r\n [^\r\n]*)*<nao-responda@wsfinancasemdia\.com\.br>/m', $headers);
            $this->assertStringNotContainsString('X-Mailer', $raw);
        }
    }

    public function test_senha_no_nome_do_remetente_e_substituida_e_apontada_no_painel(): void
    {
        config(['mail.from.name' => self::SECRETS['mail.mailers.smtp.password']]);
        SecretGuard::protectDisplayConfig();
        $this->assertSame(SecretGuard::SAFE_NAME, config('mail.from.name'));
        $this->assertSame(['MAIL_FROM_NAME contém MAIL_PASSWORD'], SecretGuard::issues());

        $owner = $this->owner();
        $this->actingAs($owner)->post('/admin/sistema/email-teste')->assertSessionHas('ok');
        $this->assertNoSecrets($this->sent());
        $html = $this->actingAs($owner)->get('/admin/sistema')->assertOk()->assertSee('CREDENCIAL ENCONTRADA')->assertSee('MAIL_FROM_NAME contém MAIL_PASSWORD')->getContent();
        $this->assertStringNotContainsString(self::SECRETS['mail.mailers.smtp.password'], $html);
    }

    public function test_senha_no_nome_do_sistema_nao_vai_para_o_layout_do_email(): void
    {
        config(['app.name' => 'Sistema '.self::SECRETS['mail.mailers.smtp.password']]);
        SecretGuard::protectDisplayConfig();
        [, $u] = $this->makeClient('profissional');
        $this->post('/esqueci-senha', ['email' => $u->email])->assertRedirect();
        $raws = $this->sent();
        $this->assertCount(1, $raws);
        $this->assertNoSecrets($raws);
        $this->assertStringContainsString('APP_NAME contém MAIL_PASSWORD', implode(' ', SecretGuard::issues()));
    }

    public function test_qualquer_email_com_credencial_e_bloqueado(): void
    {
        Log::spy();
        foreach ([
            fn () => Mail::raw('corpo', fn ($m) => $m->to('a@teste.local')->subject('assunto '.self::SECRETS['wsf.backup.password'])),
            fn () => Mail::raw('token: '.self::SECRETS['services.whatsapp.access_token'], fn ($m) => $m->to('a@teste.local')->subject('ok')),
            fn () => Mail::html('<p>'.htmlspecialchars(self::SECRETS['mail.mailers.smtp.password']).'</p>', fn ($m) => $m->to('a@teste.local')->subject('ok')),
            fn () => Mail::raw('x', fn ($m) => $m->to('a@teste.local')->subject('ok')->replyTo('r@teste.local', self::SECRETS['services.anthropic.key'])),
        ] as $send) {
            try {
                $send();
                $this->fail('e-mail com credencial não foi bloqueado');
            } catch (CredentialInEmail $e) {
                foreach (self::SECRETS as $secret) {
                    $this->assertStringNotContainsString($secret, $e->getMessage());
                }
            }
        }
        $this->assertCount(0, $this->sent(), 'nada saiu');
        Log::shouldHaveReceived('critical')->withArgs(fn ($m, $c) => ! str_contains(json_encode($c), 'Fict1cia') && ! str_contains(json_encode($c), 'EAAG'))->times(4);
    }

    public function test_bloqueio_aparece_no_painel_com_dica_sem_o_valor(): void
    {
        config(['wsf.brand.name' => self::SECRETS['mail.mailers.smtp.password']]);   // sem passar pela proteção de inicialização
        $r = app(MailDelivery::class)->sendTest('dono@teste.local');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('NÃO foi enviado', $r['dica']);
        $this->assertStringNotContainsString(self::SECRETS['mail.mailers.smtp.password'], json_encode($r));
        $this->assertCount(0, $this->sent());
    }
}
