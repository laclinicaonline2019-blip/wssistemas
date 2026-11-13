<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRule;
use App\Models\User;
use App\Services\Update\PackageInspector;
use Tests\TestCase;
use ZipArchive;

/** Validação dos pacotes de atualização (nada é alterado antes de passar por aqui). */
class UpdatePackageTest extends TestCase
{
    private function package(array $extra = [], array $manifest = [], ?string $secretKey = null, bool $tamper = false): string
    {
        $path = sys_get_temp_dir().'/wsf-pkg-'.bin2hex(random_bytes(4)).'.zip';
        $m = json_encode($manifest + ['app' => 'ws-financas', 'version' => '9.0.0', 'min_from' => '1.0.0', 'php_min' => '8.3.0']);
        $z = new ZipArchive;
        $z->open($path, ZipArchive::CREATE);
        foreach (['artisan' => '#!', 'bootstrap/app.php' => '<?php', 'public/index.php' => '<?php', 'vendor/autoload.php' => '<?php', 'VERSION' => "9.0.0\n", 'CHECKSUMS.sha256' => ''] + $extra as $n => $c) {
            $z->addFromString($n, $c);
        }
        $z->addFromString('wsf-update.json', $m);
        if ($secretKey) {
            $z->addFromString('wsf-update.sig', base64_encode(sodium_crypto_sign_detached($tamper ? $m.' ' : $m, $secretKey)));
        }
        $z->close();

        return $path;
    }

    private function inspect(string $zip): array
    {
        try {
            return app(PackageInspector::class)->inspect($zip, '1.1.0');
        } finally {
            @unlink($zip);
        }
    }

    private function assertRejected(string $zip, string $contains): void
    {
        try {
            $this->inspect($zip);
            $this->fail('pacote deveria ser recusado');
        } catch (BusinessRule $e) {
            $this->assertStringContainsString($contains, $e->getMessage());
        }
    }

    public function test_pacote_valido_sem_assinatura(): void
    {
        $r = $this->inspect($this->package());
        $this->assertSame('9.0.0', $r['manifest']['version']);
        $this->assertFalse($r['signed']);
        $this->assertNotEmpty($r['warnings']);
    }

    public function test_recusa_caminhos_e_conteudos_perigosos(): void
    {
        $this->assertRejected($this->package(['../fora.php' => 'x']), 'caminho inseguro');
        $this->assertRejected($this->package(['.env' => 'APP_KEY=x']), '.env');
        $this->assertRejected($this->package(['storage/app/installed' => 'x']), 'storage');
        $this->assertRejected($this->package(['malware.php' => 'x']), 'inesperado');
    }

    public function test_recusa_versao_antiga_e_origem_incompativel(): void
    {
        $this->assertRejected($this->package([], ['version' => '1.0.5']), 'não é mais nova');
        $this->assertRejected($this->package([], ['min_from' => '2.0.0']), 'exige a versão 2.0.0');
        $this->assertRejected($this->package([], ['app' => 'outro']), 'não é uma atualização');
        $this->assertRejected($this->package([], ['php_min' => '99.0.0']), 'exige PHP');
    }

    public function test_assinatura_ed25519(): void
    {
        $kp = sodium_crypto_sign_keypair();
        config(['wsf.update.public_key' => base64_encode(sodium_crypto_sign_publickey($kp))]);
        $r = $this->inspect($this->package([], [], sodium_crypto_sign_secretkey($kp)));
        $this->assertTrue($r['signature_ok']);
        $this->assertRejected($this->package([], [], sodium_crypto_sign_secretkey($kp), tamper: true), 'INVÁLIDA');
        $this->assertRejected($this->package(), 'exige pacotes assinados');
        $other = sodium_crypto_sign_keypair();
        $this->assertRejected($this->package([], [], sodium_crypto_sign_secretkey($other)), 'INVÁLIDA');
    }

    public function test_tela_de_atualizacoes_exige_proprietario(): void
    {
        [, $u] = $this->makeClient();
        $this->actingAs($u)->get('/admin/atualizacoes')->assertNotFound();
        $owner = User::query()->create(['name' => 'Dono', 'email' => 'dono@t.local', 'password' => 'Senha@Forte123', 'is_platform_admin' => true]);
        $this->actingAs($owner)->get('/admin/atualizacoes')->assertOk()->assertSee('Versão instalada');
        $this->actingAs($owner)->post('/admin/atualizacoes/iniciar', ['pacote' => 'x.zip', 'password' => 'errada', 'ciente' => 1])->assertSessionHasErrors('password');
    }
}
