<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRule;
use App\Services\Install\EnvFile;
use App\Services\Install\InstallGuard;
use App\Services\Install\WebInstaller;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PDO;

/**
 * Instalador web: roda num storage e .env temporários (nunca toca no .env real do projeto).
 * O fluxo completo com banco usa MySQL/MariaDB se WSF_TEST_MYSQL_DB estiver definido (CI e local).
 */
class WebInstallerTest extends BaseTestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/wsf-inst-'.bin2hex(random_bytes(4));
        mkdir($this->tmp.'/storage/app', 0777, true);
        copy(base_path('.env.example'), $this->tmp.'/.env');
        $this->app->useStoragePath($this->tmp.'/storage');
        $this->app->useEnvironmentPath($this->tmp);
        $this->app->loadEnvironmentFrom('.env');
        config(['cache.default' => 'array']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmp));
        parent::tearDown();
    }

    private function code(): string
    {
        return explode("\n", (string) file_get_contents($this->tmp.'/storage/app/instalador/CODIGO-DE-INSTALACAO.txt'))[2];
    }

    private function login(): string
    {
        $this->get('/instalar')->assertOk()->assertSee('CODIGO-DE-INSTALACAO.txt');
        $r = $this->post('/instalar/codigo', ['codigo' => $this->code()])->assertRedirect('/instalar');
        $cookie = collect($r->headers->getCookies())->first(fn ($c) => $c->getName() === InstallGuard::COOKIE);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());

        return $cookie->getValue();
    }

    private function csrf(string $cookie): string
    {
        $html = $this->withUnencryptedCookie(InstallGuard::COOKIE, $cookie)->get('/instalar')->getContent();
        preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $m);

        return $m[1];
    }

    public function test_codigo_obrigatorio_e_trocado_apos_tentativas_erradas(): void
    {
        $this->get('/instalar')->assertOk();
        $first = $this->code();
        $this->assertSame('600', substr(sprintf('%o', fileperms($this->tmp.'/storage/app/instalador/CODIGO-DE-INSTALACAO.txt')), -3));
        for ($i = 0; $i < 4; $i++) {
            $this->post('/instalar/codigo', ['codigo' => 'ERRADO'])->assertStatus(422)->assertSee('Código incorreto');
        }
        $this->post('/instalar/codigo', ['codigo' => 'ERRADO'])->assertSee('Muitas tentativas');
        $this->assertNotSame($first, $this->code());
        $this->post('/instalar/codigo', ['codigo' => $first])->assertStatus(422);
    }

    public function test_etapas_exigem_sessao_e_csrf_do_instalador(): void
    {
        $this->post('/instalar/chaves')->assertRedirect('/instalar');
        $cookie = $this->login();
        $this->withUnencryptedCookie(InstallGuard::COOKIE, $cookie)->post('/instalar/chaves', ['_csrf' => 'invalido'])->assertStatus(419);
        $this->withUnencryptedCookie(InstallGuard::COOKIE, 'cookie-falso')->get('/instalar')->assertSee('Código de instalação');
    }

    public function test_gera_app_key_e_segredos_sem_terminal_e_mantem_chave_existente(): void
    {
        $cookie = $this->login();
        $this->withUnencryptedCookie(InstallGuard::COOKIE, $cookie)->post('/instalar/requisitos', ['_csrf' => $this->csrf($cookie)])->assertRedirect('/instalar');
        $this->withUnencryptedCookie(InstallGuard::COOKIE, $cookie)->post('/instalar/chaves', ['_csrf' => $this->csrf($cookie)])->assertRedirect('/instalar');
        $env = new EnvFile($this->tmp.'/.env');
        $key = $env->get('APP_KEY');
        $this->assertMatchesRegularExpression('#^base64:[A-Za-z0-9+/=]{44}$#', (string) $key);
        $this->assertSame(32, strlen(base64_decode(substr($key, 7))));
        foreach (['AUDIT_HMAC_KEY', 'BACKUP_PASSWORD', 'WHATSAPP_VERIFY_TOKEN', 'ASAAS_WEBHOOK_TOKEN'] as $k) {
            $this->assertNotEmpty($env->get($k), $k);
        }
        $this->assertSame('600', substr(sprintf('%o', fileperms($this->tmp.'/.env')), -3));
        $this->assertFileExists($this->tmp.'/storage/framework/views');
        // refazer a etapa não troca a chave (dados criptografados continuariam legíveis)
        $this->withUnencryptedCookie(InstallGuard::COOKIE, $cookie)->post('/instalar/chaves', ['_csrf' => $this->csrf($cookie)]);
        $this->assertSame($key, (new EnvFile($this->tmp.'/.env'))->get('APP_KEY'));
    }

    public function test_instalador_bloqueado_apos_instalacao(): void
    {
        file_put_contents($this->tmp.'/storage/app/installed', '{}');
        $this->get('/instalar')->assertNotFound();
        $this->post('/instalar/codigo', ['codigo' => 'x'])->assertNotFound();
        $this->post('/instalar/banco', ['_csrf' => 'x'])->assertNotFound();
    }

    public function test_env_recusa_injecao_e_preserva_senhas_com_caracteres_especiais(): void
    {
        $env = new EnvFile($this->tmp.'/.env');
        foreach (['S3nh@#Banco$x', 'a b c', 'com"aspas', "com'apostrofo", 'x=y&z!%'] as $pw) {
            $env->set(['DB_PASSWORD' => $pw]);
            $this->assertSame($pw, (new EnvFile($this->tmp.'/.env'))->get('DB_PASSWORD'));
        }
        $before = file_get_contents($this->tmp.'/.env');
        foreach (["x\nAPP_DEBUG=true", "x\rY", "x\0"] as $evil) {
            try {
                $env->set(['DB_PASSWORD' => $evil]);
                $this->fail('deveria recusar');
            } catch (BusinessRule) {
            }
        }
        $this->assertSame($before, file_get_contents($this->tmp.'/.env'));
        $this->expectException(BusinessRule::class);
        $env->set(['lower-case' => 'x']);
    }

    public function test_validacao_dos_dados_do_banco(): void
    {
        $i = app(WebInstaller::class);
        foreach ([['db_host' => 'localhost; rm -rf', 'db_database' => 'a', 'db_username' => 'b'], ['db_host' => 'localhost', 'db_database' => 'a b', 'db_username' => 'b'],
            ['db_host' => 'localhost', 'db_database' => 'ok', 'db_username' => "x'--"]] as $bad) {
            try {
                $i->validateDbInput($bad + ['db_port' => 3306]);
                $this->fail('deveria recusar '.json_encode($bad));
            } catch (BusinessRule) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_fluxo_completo_com_mysql(): void
    {
        $db = getenv('WSF_TEST_MYSQL_DB');
        if (! $db) {
            $this->markTestSkipped('Defina WSF_TEST_MYSQL_DB/USER/PASS/HOST para testar com MySQL.');
        }
        $host = getenv('WSF_TEST_MYSQL_HOST') ?: '127.0.0.1';
        $user = getenv('WSF_TEST_MYSQL_USER') ?: 'root';
        $pass = getenv('WSF_TEST_MYSQL_PASS') ?: '';
        $pdo = new PDO("mysql:host=$host", $user, $pass);
        $pdo->exec("DROP DATABASE IF EXISTS `$db`; CREATE DATABASE `$db` CHARACTER SET utf8mb4");

        $cookie = $this->login();
        $post = fn (string $step, array $data = []) => $this->withUnencryptedCookie(InstallGuard::COOKIE, $cookie)->post('/instalar/'.$step, $data + ['_csrf' => $this->csrf($cookie)]);
        $post('requisitos');
        $post('chaves');
        $post('banco', ['db_host' => $host, 'db_port' => 3306, 'db_database' => $db, 'db_username' => $user, 'db_password' => 'errada']);
        $this->assertNull((new EnvFile($this->tmp.'/.env'))->get('DB_DATABASE') === $db ? 'x' : null, 'senha errada não grava');
        $post('banco', ['db_host' => $host, 'db_port' => 3306, 'db_database' => $db, 'db_username' => $user, 'db_password' => $pass]);
        $post('aplicacao', ['app_url' => 'https://teste.wsfinancasemdia.com.br', 'stage' => 'homologation']);
        $post('migracoes');
        $post('proprietario', ['name' => 'Dono', 'email' => 'dono@t.local', 'password' => 'Senha@Forte123', 'password_confirmation' => 'Senha@Forte123']);
        $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM `$db`.users WHERE is_platform_admin = 1")->fetchColumn());
        $this->assertSame(3, (int) $pdo->query("SELECT COUNT(*) FROM `$db`.plans")->fetchColumn());
        $post('concluir')->assertOk()->assertSee('Instalação concluída');
        $env = new EnvFile($this->tmp.'/.env');
        $this->assertSame('true', $env->get('WSF_INSTALLER_LOCKED'));
        $this->assertSame('homologation', $env->get('APP_STAGE'));
        $this->assertFileExists($this->tmp.'/storage/app/installed');
        $this->assertFileDoesNotExist($this->tmp.'/storage/app/instalador/CODIGO-DE-INSTALACAO.txt');
        $this->assertSame([], glob($this->tmp.'/storage/app/instalador/*.bak') ?: []);
        $this->get('/instalar')->assertNotFound();
        $pdo->exec("DROP DATABASE `$db`");
    }
}
