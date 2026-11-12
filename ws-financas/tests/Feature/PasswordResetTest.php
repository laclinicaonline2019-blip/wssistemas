<?php

namespace Tests\Feature;

use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** "Esqueci minha senha": o link vai para o e-mail cadastrado e permite definir uma nova senha. */
class PasswordResetTest extends TestCase
{
    public function test_link_vai_para_o_email_cadastrado_e_redefine_a_senha(): void
    {
        Notification::fake();
        [, $u] = $this->makeClient(email: 'cliente@empresa.com.br');

        $this->get('/entrar')->assertSee('Esqueci minha senha');
        $this->post('/esqueci-senha', ['email' => 'Cliente@Empresa.com.br'])->assertSessionHas('ok');

        $token = null;
        Notification::assertSentTo($u, ResetPasswordNotification::class, function ($n, $channels) use (&$token, $u) {
            $token = $n->token;
            $mail = $n->toMail($u);
            $this->assertStringContainsString('/redefinir-senha/', $mail->actionUrl);
            $this->assertStringContainsString(urlencode('cliente@empresa.com.br'), $mail->actionUrl);

            return $channels === ['mail'];
        });

        $this->get('/redefinir-senha/'.$token.'?email=cliente@empresa.com.br')->assertOk()->assertSee('Criar nova senha');
        $this->post('/redefinir-senha', ['token' => $token, 'email' => 'cliente@empresa.com.br', 'password' => 'NovaSenha@2026', 'password_confirmation' => 'NovaSenha@2026'])
            ->assertRedirect('/entrar');
        $this->assertTrue(Hash::check('NovaSenha@2026', $u->fresh()->password));

        // o mesmo link não funciona duas vezes
        $this->post('/redefinir-senha', ['token' => $token, 'email' => 'cliente@empresa.com.br', 'password' => 'OutraSenha@2026', 'password_confirmation' => 'OutraSenha@2026'])
            ->assertSessionHas('error');
    }

    public function test_email_inexistente_tem_resposta_neutra_e_nada_e_enviado(): void
    {
        Notification::fake();
        $this->post('/esqueci-senha', ['email' => 'ninguem@x.com'])->assertSessionHas('ok');
        Notification::assertNothingSent();
    }
}
