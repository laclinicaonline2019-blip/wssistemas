<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * 1.1.6 — 2FA opcional para clientes (escolha no cadastro, ativar/desativar com confirmação segura),
 * obrigatório para o proprietário da plataforma; mensagens e e-mails em português do Brasil.
 */
class TwoFactorOptionalAndPtBrTest extends TestCase
{
    private const PW = 'Senha@Forte123';

    private function signup(string $email, bool $twoFactor)
    {
        return $this->post('/cadastro', ['person_type' => 'pf', 'name' => 'Cliente '.$email, 'admin_name' => 'Fulano', 'email' => $email, 'password' => self::PW,
            'password_confirmation' => self::PW, 'plan' => 'profissional', 'cycle' => 'monthly', 'accept' => '1', 'two_factor' => $twoFactor ? '1' : '0']);
    }

    private function otp(User $u): string
    {
        Cache::forget('2fa:last-ts:'.$u->id);   // o mesmo código não pode ser reutilizado na mesma janela (proteção contra repetição)

        return (new Google2FA)->getCurrentOtp((string) $u->fresh()->two_factor_secret);
    }

    private function enable(User $u): void
    {
        $this->actingAs($u)->get('/seguranca/2fa')->assertOk();
        $secret = (string) session('2fa:setup');
        Cache::forget('2fa:last-ts:'.$u->id);
        $this->actingAs($u)->post('/seguranca/2fa', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk()->assertSee('recupera');
        $this->assertTrue($u->fresh()->hasTwoFactor());
    }

    public function test_padrao_da_configuracao_deixa_o_2fa_do_cliente_opcional_e_o_do_proprietario_obrigatorio(): void
    {
        putenv('WSF_2FA_ADMIN_CLIENTE=true');   // linha antiga que veio no .env da instalação: não é mais lida
        $cfg = (fn () => require config_path('wsf.php'))();
        putenv('WSF_2FA_ADMIN_CLIENTE');
        $this->assertFalse($cfg['security']['account_admin_2fa']);
        $this->assertTrue($cfg['security']['platform_admin_2fa']);
    }

    public function test_cadastro_sem_2fa_entra_direto_e_faz_login_so_com_senha(): void
    {
        config(['wsf.security.platform_admin_2fa' => true, 'wsf.security.account_admin_2fa' => false]);
        $this->signup('sem2fa@teste.local', false)->assertRedirect('/app');
        $this->get('/app')->assertOk();
        $this->get('/seguranca')->assertOk()->assertSee('Desativada');
        $this->post('/sair');
        $this->post('/entrar', ['email' => 'sem2fa@teste.local', 'password' => self::PW])->assertRedirect('/app');
        $this->get('/app')->assertOk();
        $this->assertFalse(User::query()->where('email', 'sem2fa@teste.local')->sole()->hasTwoFactor());
    }

    public function test_cadastro_com_2fa_pede_ativacao_e_depois_exige_o_codigo_no_login(): void
    {
        config(['wsf.security.platform_admin_2fa' => true, 'wsf.security.account_admin_2fa' => false]);
        $this->signup('com2fa@teste.local', true)->assertRedirect(route('security.2fa'));
        $this->get('/seguranca/2fa')->assertOk()->assertSee('Agora não');   // cliente pode deixar para depois
        $u = User::query()->where('email', 'com2fa@teste.local')->sole();
        $this->enable($u);
        $this->post('/sair');

        $this->post('/entrar', ['email' => 'com2fa@teste.local', 'password' => self::PW])->assertRedirect(route('login.2fa'));
        $this->get('/app')->assertRedirect(route('login'));                     // sem o código não entra
        $this->post('/entrar/verificacao', ['code' => '000000'])->assertSessionHas('error', 'Código inválido.');
        $this->post('/entrar/verificacao', ['code' => $this->otp($u)])->assertRedirect('/app');
        $this->get('/app')->assertOk();
    }

    public function test_desativar_exige_senha_e_codigo_validos(): void
    {
        [, $u] = $this->makeClient('profissional');
        $this->enable($u);
        $this->actingAs($u)->delete('/seguranca/2fa', ['password' => 'errada', 'code' => $this->otp($u)])->assertSessionHasErrors('password');
        $this->actingAs($u)->delete('/seguranca/2fa', ['password' => self::PW])->assertSessionHasErrors('code');
        $this->actingAs($u)->delete('/seguranca/2fa', ['password' => self::PW, 'code' => '123456'])->assertSessionHasErrors('code');
        $this->assertTrue($u->fresh()->hasTwoFactor());
        $this->actingAs($u)->delete('/seguranca/2fa', ['password' => self::PW, 'code' => $this->otp($u)])->assertSessionHas('ok');
        $this->assertFalse($u->fresh()->hasTwoFactor());
        $this->assertTrue(AuditLog::query()->where('action', 'seguranca.2fa_desativar')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'seguranca.2fa_desativar_recusado')->exists());

        // reativar depois, pelas configurações
        $this->enable($u);
    }

    public function test_proprietario_da_plataforma_continua_obrigado(): void
    {
        config(['wsf.security.platform_admin_2fa' => true, 'wsf.security.account_admin_2fa' => false]);
        $owner = User::query()->create(['name' => 'Dono', 'email' => 'dono@teste.local', 'password' => self::PW, 'is_platform_admin' => true, 'status' => 'active']);
        $this->actingAs($owner)->get('/admin')->assertRedirect(route('security.2fa'));
        $this->actingAs($owner)->get('/seguranca/2fa')->assertOk()->assertDontSee('Agora não');
        $this->enable($owner);
        $this->actingAs($owner)->get('/admin')->assertOk();
        $this->actingAs($owner)->delete('/seguranca/2fa', ['password' => self::PW, 'code' => $this->otp($owner)])->assertSessionHas('error');
        $this->assertTrue($owner->fresh()->hasTwoFactor());
    }

    public function test_mensagens_de_validacao_em_portugues(): void
    {
        [, $u] = $this->makeClient('profissional');
        $errors = [];
        $collect = function ($r) use (&$errors) {
            $e = session('errors');
            $list = is_object($e) ? $e->all() : collect($e['default']['messages'] ?? [])->flatten()->all();
            $errors = array_merge($errors, $list);
        };
        $collect($this->post('/cadastro', ['person_type' => 'pf', 'email' => 'invalido', 'password' => 'abc', 'password_confirmation' => 'x', 'plan' => 'basico', 'cycle' => 'monthly']));
        $collect($this->post('/entrar', []));
        $collect($this->post('/esqueci-senha', ['email' => 'x']));
        $collect($this->post('/redefinir-senha', ['token' => 't', 'email' => $u->email, 'password' => 'Abcdefghij1', 'password_confirmation' => 'Abcdefghij1']));
        $collect($this->actingAs($u)->post('/seguranca/senha', ['current_password' => 'errada', 'password' => 'curta', 'password_confirmation' => 'curta']));
        $this->assertNotEmpty($errors);
        $this->assertContains('A senha deve conter pelo menos um caractere especial.', $errors);
        foreach ($errors as $msg) {
            $this->assertDoesNotMatchRegularExpression('/\b(The|field|must|required|least|characters|confirmation does not)\b/', $msg, 'mensagem em inglês: '.$msg);
        }
    }

    public function test_email_de_recuperacao_em_portugues_e_link_funcionando(): void
    {
        [, $u] = $this->makeClient('profissional');
        Notification::fake();
        $this->post('/esqueci-senha', ['email' => $u->email])->assertSessionHas('ok');
        $token = null;
        Notification::assertSentTo($u, ResetPasswordNotification::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });
        $mail = (new ResetPasswordNotification($token))->toMail($u);
        $html = (string) $mail->render();
        $text = strip_tags(html_entity_decode($html));
        foreach (['Redefinição de senha', 'Criar nova senha', 'Se não conseguir clicar no botão', 'Todos os direitos reservados'] as $pt) {
            $this->assertStringContainsString($pt, $mail->subject.' '.$text);
        }
        $this->assertDoesNotMatchRegularExpression('/Hello|Regards|having trouble|copy and paste|All rights reserved|Whoops/', $text);

        // o link do e-mail abre a tela e redefine a senha
        $this->get('/redefinir-senha/'.$token.'?email='.urlencode($u->email))->assertOk()->assertSee('senha');
        $this->post('/redefinir-senha', ['token' => $token, 'email' => $u->email, 'password' => 'Nova@Senha2026', 'password_confirmation' => 'Nova@Senha2026'])
            ->assertRedirect(route('login'))->assertSessionHas('ok');
        $this->post('/entrar', ['email' => $u->email, 'password' => 'Nova@Senha2026'])->assertRedirect();
        $this->assertAuthenticatedAs($u->fresh());
    }
}
