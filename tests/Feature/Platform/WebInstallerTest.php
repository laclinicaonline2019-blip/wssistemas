<?php

namespace Tests\Feature\Platform;

use App\Core\Install\Installer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WebInstallerTest extends TestCase
{
    private const TOKEN = 'token-de-instalacao-super-secreto';

    protected function setUp(): void
    {
        parent::setUp();
        config(['aivexa.install_token' => self::TOKEN]);
        File::delete(app(Installer::class)->lockPath());
    }

    protected function tearDown(): void
    {
        File::delete(app(Installer::class)->lockPath());
        parent::tearDown();
    }

    public function test_installer_is_hidden_without_token_configuration(): void
    {
        config(['aivexa.install_token' => null]);

        $this->get('/instalar')->assertNotFound();
    }

    public function test_wrong_token_is_rejected(): void
    {
        $this->get('/instalar')->assertOk()->assertSee('Token de instalação');
        $this->post('/instalar', ['token' => 'errado'])->assertOk()->assertSee('Token de instalação inválido');
    }

    public function test_full_web_installation_then_installer_disappears(): void
    {
        $this->post('/instalar', ['token' => self::TOKEN])->assertOk()->assertSee('Verificação do servidor');

        $payload = [
            'token' => self::TOKEN, 'step' => 'install',
            'legal_name' => 'Clínica Web Ltda', 'trade_name' => 'Clínica Web', 'document' => '11.222.333/0001-81',
            'admin_name' => 'Gestora', 'admin_email' => 'gestora@example.test',
            'admin_password' => 'Senha@Forte123', 'admin_password_confirmation' => 'Senha@Forte123',
            'super_name' => 'Root', 'super_email' => 'root@example.test',
            'super_password' => 'Senha@Forte456', 'super_password_confirmation' => 'Senha@Forte456',
        ];

        $this->post('/instalar', [...$payload, 'admin_password_confirmation' => 'x'])->assertOk()->assertSee('não confere');
        $this->assertSame(0, DB::table('companies')->count());

        $this->post('/instalar', $payload)->assertOk()->assertSee('Instalação concluída');

        $this->assertDatabaseHas('companies', ['trade_name' => 'Clínica Web', 'document' => '11222333000181']);
        $this->assertDatabaseHas('users', ['email' => 'root@example.test', 'is_super_admin' => true]);
        $this->assertTrue(app(Installer::class)->isInstalled());

        $this->get('/instalar')->assertNotFound();
        $this->post('/instalar', $payload)->assertNotFound();
    }
}
