<?php

namespace Tests\Feature;

use App\Http\Controllers\Staff\ReportController;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use App\Models\WaMessage;
use App\Services\LoyaltyService;
use App\Services\WhatsApp\MockGateway;
use App\Services\WhatsApp\WhatsAppGateway;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** Testes de segurança: arquivos maliciosos, força bruta, 2FA, assinatura, privacidade e LGPD. */
class SegurancaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        MockGateway::$sent = [];
    }

    private function admin(): User
    {
        return User::where('role', 'admin')->first();
    }

    public function test_arquivo_malicioso_pelo_whatsapp_e_descartado(): void
    {
        $html = '<html><body><script>fetch("/painel/usuarios")</script></body></html>';
        $this->postJson('/webhook/whatsapp/segredo-teste', ['phone' => '5511900001111', 'text' => 'oi', 'image_base64' => base64_encode($html), 'mime' => 'image/jpeg'])->assertOk();
        $msg = WaMessage::where('direction', 'in')->firstOrFail();
        $this->assertNull($msg->media_path, 'HTML disfarçado de imagem não é guardado');
        $this->assertSame('text', $msg->type);
    }

    public function test_arquivo_guardado_so_abre_no_navegador_se_for_imagem_ou_pdf(): void
    {
        Setting::put('require_2fa_admin', '0');
        $c = Customer::first();
        Storage::disk('local')->put('whatsapp/x.ogg', '<svg onload=alert(1)>');
        $m = WaMessage::create(['customer_id' => $c->id, 'phone' => $c->phone, 'direction' => 'in', 'type' => 'document', 'media_path' => 'whatsapp/x.ogg', 'mime' => 'text/html']);
        $res = $this->actingAs($this->admin())->get(route('file', ['mensagem', $m->id]))->assertOk();
        $this->assertSame('application/octet-stream', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('sandbox', $res->headers->get('Content-Security-Policy'));
        // farmácia e visitante não acessam arquivos
        $this->actingAs(User::where('role', 'farmacia')->first())->get(route('file', ['mensagem', $m->id]))->assertForbidden();
    }

    public function test_codigo_do_cliente_bloqueia_apos_5_tentativas(): void
    {
        $c = Customer::first();
        $this->post(route('portal.code'), ['phone' => $c->phone]);
        for ($i = 0; $i < 4; $i++) {
            $this->post(route('portal.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        }
        $this->post(route('portal.verify'), ['code' => '000000'])->assertRedirect(route('portal.login'));
        $this->assertNull($c->fresh()->portal_code, 'código invalidado');
        // limite de envio de códigos por número
        foreach (range(1, 4) as $i) {
            $this->post(route('portal.code'), ['phone' => $c->phone]);
        }
        $this->assertLessThanOrEqual(3, count(array_filter(MockGateway::$sent, fn ($m) => str_contains($m['text'], 'código de acesso'))));
    }

    public function test_login_bloqueia_conta_apos_5_senhas_erradas(): void
    {
        foreach (range(1, 5) as $i) {
            $this->post('/login', ['email' => 'televendas1@demo.local', 'password' => 'errada']);
        }
        $this->post('/login', ['email' => 'televendas1@demo.local', 'password' => 'Demo@12345'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_verificacao_em_duas_etapas(): void
    {
        $admin = $this->admin();
        // obrigatória para administrador
        $this->actingAs($admin)->get(route('dashboard'))->assertRedirect(route('2fa.setup'));
        $this->get(route('2fa.setup'))->assertOk()->assertSee('<svg', false);
        $secret = session('2fa:new_secret');
        $g = new Google2FA;
        $this->post(route('2fa.enable'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post(route('2fa.enable'), ['code' => $g->getCurrentOtp($secret)])->assertSessionHas('ok');
        $this->assertNotNull($admin->fresh()->two_factor_confirmed_at);
        $this->assertNotSame($secret, $admin->fresh()->getRawOriginal('two_factor_secret'), 'segredo guardado criptografado');
        $this->get(route('dashboard'))->assertOk();

        // novo login: senha certa ainda pede o código
        auth()->logout();
        $this->post('/login', ['email' => $admin->email, 'password' => 'Trocar@123'])->assertRedirect(route('2fa.challenge'));
        $this->assertGuest();
        $this->post(route('2fa.verify'), ['code' => '123456'])->assertSessionHasErrors('code');
        $this->post(route('2fa.verify'), ['code' => $g->getCurrentOtp($secret)])->assertRedirect('/painel');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_webhook_da_meta_exige_assinatura(): void
    {
        config(['pesquisafarma.whatsapp_driver' => 'meta', 'pesquisafarma.meta.app_secret' => 'segredo-app']);
        $this->app->forgetInstance(WhatsAppGateway::class);
        $body = json_encode(['entry' => []]);
        $this->call('POST', '/webhook/whatsapp/segredo-teste', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X-Hub-Signature-256' => 'sha256=falsa'], $body)->assertForbidden();
        $sig = 'sha256='.hash_hmac('sha256', $body, 'segredo-app');
        $this->call('POST', '/webhook/whatsapp/segredo-teste', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X-Hub-Signature-256' => $sig], $body)->assertOk();
    }

    public function test_planilha_neutraliza_formulas(): void
    {
        $this->assertSame("'=HYPERLINK(\"http://x\")", ReportController::cell('=HYPERLINK("http://x")'));
        $this->assertSame("'+5511", ReportController::cell('+5511'));
        $this->assertSame('Maria', ReportController::cell('Maria'));
        $this->assertSame('12,50', ReportController::cell(12.5));
    }

    public function test_balcao_nao_permite_chutar_numero_do_cartao(): void
    {
        $c = Customer::first();
        $pharmacy = User::where('email', 'farmacia@demo.local')->first();
        $this->actingAs($pharmacy)->get(route('panel.card', ['cartao' => (string) $c->id]))->assertDontSee('Cartão válido');
        $this->get(route('panel.card', ['cartao' => 'PF '.str_pad((string) $c->id, 6, '0', STR_PAD_LEFT).'-0'.'']))->assertOk();
        $ok = $this->get(route('panel.card', ['cartao' => LoyaltyService::cardNumber($c)]))->assertSee('Cartão válido');
        $ok->assertDontSee($c->name, false); // só o primeiro nome
        $ok->assertSee(explode(' ', $c->name)[0]);
    }

    public function test_lgpd_exporta_e_anonimiza(): void
    {
        Setting::put('require_2fa_admin', '0');
        $c = Customer::first();
        WaMessage::create(['customer_id' => $c->id, 'phone' => $c->phone, 'direction' => 'in', 'body' => 'meu CPF é 123']);
        $this->actingAs($this->admin())->get(route('customers.export', $c))->assertOk()->assertHeader('content-type', 'application/json');
        $this->post(route('customers.anonymize', $c), ['confirm' => 'errado'])->assertSessionHasErrors('confirm');
        $this->post(route('customers.anonymize', $c), ['confirm' => 'ANONIMIZAR'])->assertSessionHas('ok');
        $c->refresh();
        $this->assertNotNull($c->anonymized_at);
        $this->assertStringStartsWith('Cliente anonimizado', $c->name);
        $this->assertNull($c->address);
        $this->assertSame(0, WaMessage::where('customer_id', $c->id)->count());
        // televendas não pode anonimizar
        $this->actingAs(User::where('role', 'televendas')->first())->post(route('customers.anonymize', $c), ['confirm' => 'ANONIMIZAR'])->assertForbidden();
    }

    public function test_cabecalhos_de_seguranca(): void
    {
        $res = $this->get('/login')->assertOk();
        $this->assertSame('SAMEORIGIN', $res->headers->get('X-Frame-Options'));
        $this->assertStringContainsString("script-src 'self'", $res->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
    }

    public function test_verificacao_de_producao_aponta_o_que_falta(): void
    {
        $this->artisan('pf:verificar')->expectsOutputToContain('WHATSAPP_WEBHOOK_SECRET')->assertFailed();
    }
}
