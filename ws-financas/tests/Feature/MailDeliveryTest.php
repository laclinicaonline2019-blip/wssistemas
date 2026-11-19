<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Services\MailDelivery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Convite de cliente, novo usuário e "esqueci a senha": falha de SMTP nunca vira HTTP 500 e fica diagnosticada. */
class MailDeliveryTest extends TestCase
{
    private const PW = 'S3nh@ #Secreta$Titan';

    private function owner(): User
    {
        return User::query()->create(['name' => 'Dono', 'email' => 'dono@teste.local', 'password' => 'Senha@Forte123', 'is_platform_admin' => true, 'status' => 'active']);
    }

    /** SMTP real apontando para uma porta fechada: falha de conexão como na hospedagem. */
    private function brokenSmtp(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.scheme' => 'smtps',
            'mail.mailers.smtp.username' => 'nao-responda@wsfinancasemdia.com.br', 'mail.mailers.smtp.password' => self::PW, 'mail.mailers.smtp.timeout' => 3]);
    }

    private function newClient(): TestResponse
    {
        return $this->actingAs($this->owner())->post('/admin/clientes', ['name' => 'Cliente Convite', 'person_type' => 'pf', 'email' => 'convite@teste.local',
            'admin_name' => 'Fulano', 'plan' => 'profissional', 'cycle' => 'monthly', 'trial_days' => 14]);
    }

    public function test_convite_de_cliente_e_enviado(): void
    {
        Notification::fake();
        $this->newClient()->assertRedirect()->assertSessionHas('ok');
        $u = User::query()->where('email', 'convite@teste.local')->sole();
        Notification::assertSentTo($u, ResetPasswordNotification::class);
    }

    public function test_esqueci_a_senha_com_smtp_fora_do_ar_nao_da_500(): void
    {
        [, $u] = $this->makeClient('profissional');
        $this->brokenSmtp();
        Log::spy();
        $this->post('/esqueci-senha', ['email' => $u->email])->assertRedirect()->assertSessionHas('ok');   // resposta neutra, sem erro 500
        Log::shouldHaveReceived('error')->withArgs(fn ($m, $c) => $m === 'E-mail não enviado' && $c['contexto'] === 'esqueci_senha' && ! str_contains(json_encode($c), self::PW))->once();
        $last = Cache::get(MailDelivery::LAST_KEY);
        $this->assertFalse($last['ok']);
        $this->assertStringContainsString('MAIL_HOST', $last['dica']);
    }

    public function test_apos_falha_novo_pedido_de_senha_e_enviado_sem_esperar(): void
    {
        [, $u] = $this->makeClient('profissional');
        $this->brokenSmtp();
        $this->post('/esqueci-senha', ['email' => $u->email])->assertRedirect();
        Notification::fake();
        config(['mail.default' => 'array']);
        $this->post('/esqueci-senha', ['email' => $u->email])->assertRedirect();   // logo em seguida, SMTP corrigido
        Notification::assertSentTo($u, ResetPasswordNotification::class);
    }

    public function test_cliente_criado_mesmo_sem_smtp_avisa_e_permite_reenviar(): void
    {
        $this->brokenSmtp();
        $r = $this->newClient()->assertRedirect()->assertSessionHas('error');
        $this->assertStringContainsString('Reenviar convite', session('error'));
        $account = Account::query()->where('email', 'convite@teste.local')->sole();

        // SMTP corrigido → reenviar convite pelo painel do proprietário
        Notification::fake();
        config(['mail.default' => 'array']);
        $this->actingAs(User::query()->where('email', 'dono@teste.local')->sole())
            ->post('/admin/clientes/'.$account->id.'/acao', ['action' => 'resend_invite', 'email' => 'convite@teste.local'])->assertSessionHas('ok');
        Notification::assertSentTo(User::query()->where('email', 'convite@teste.local')->sole(), ResetPasswordNotification::class);
        // não envia para e-mail de outra conta
        $this->actingAs(User::query()->where('email', 'dono@teste.local')->sole())
            ->post('/admin/clientes/'.$account->id.'/acao', ['action' => 'resend_invite', 'email' => 'dono@teste.local'])->assertNotFound();
    }

    public function test_novo_usuario_do_cliente_sem_smtp_nao_da_500(): void
    {
        [, $admin] = $this->makeClient('empresarial');
        $this->brokenSmtp();
        $this->actingAs($admin)->post('/app/usuarios', ['name' => 'Financeiro', 'email' => 'fin@teste.local', 'role' => 'financeiro', 'all_companies' => '1'])
            ->assertRedirect()->assertSessionHas('error');
        $this->assertStringContainsString('não foi enviado', session('error'));
        $this->assertTrue(User::query()->where('email', 'fin@teste.local')->exists());
    }

    public function test_painel_teste_de_email_e_diagnostico_sem_senha(): void
    {
        $owner = $this->owner();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.titan.email', 'mail.mailers.smtp.port' => 465, 'mail.mailers.smtp.scheme' => 'smtps',
            'mail.mailers.smtp.username' => 'nao-responda@wsfinancasemdia.com.br', 'mail.mailers.smtp.password' => self::PW, 'mail.from.address' => 'nao-responda@wsfinancasemdia.com.br']);
        $html = $this->actingAs($owner)->get('/admin/sistema')->assertOk()->assertSee('E-mail — convites e recuperação de senha')->assertSee('smtp.titan.email')
            ->assertSee('aspas simples')->getContent();
        $this->assertStringNotContainsString(self::PW, $html);

        config(['mail.default' => 'array']);
        $this->actingAs($owner)->post('/admin/sistema/email-teste')->assertSessionHas('ok');
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());

        $this->brokenSmtp();
        $this->actingAs($owner)->post('/admin/sistema/email-teste')->assertSessionHas('error');
        $this->assertStringNotContainsString(self::PW, session('error'));
    }

    public function test_dicas_por_tipo_de_erro_e_senha_mascarada(): void
    {
        config(['mail.mailers.smtp.password' => self::PW]);
        $this->assertStringNotContainsString(self::PW, MailDelivery::sanitize('Failed to authenticate using '.self::PW.' / '.base64_encode(self::PW)));
        $this->assertStringContainsString('senha', MailDelivery::hint('Failed to authenticate on SMTP server with username "x": 535 5.7.8 Authentication failed'));
        $this->assertStringContainsString('587', MailDelivery::hint('Connection to "smtp.titan.email:465" timed out.'));
        $this->assertStringContainsString('MAIL_FROM_ADDRESS', MailDelivery::hint('Expected response code "250" but got code "553", with message "553 Sender address rejected: not owned by user".'));
        $this->assertSame(15, (int) config('mail.mailers.smtp.timeout'));
    }
}
