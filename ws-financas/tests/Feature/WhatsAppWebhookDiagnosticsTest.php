<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappContact;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Recebimento e gravação do webhook do WhatsApp + diagnóstico seguro (1.1.2):
 * chegou ao Laravel · assinatura · conteúdo · gravação · processamento · resposta · status da Meta — sem conteúdo privado.
 */
class WhatsAppWebhookDiagnosticsTest extends TestCase
{
    private const PHONE = '5511987654321';

    private const SECRET = '0123456789abcdef0123456789abcdef';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/wsf-wa-diag-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/logs');
        File::ensureDirectoryExists($this->dir.'/app');
        File::put($this->dir.'/app/installed', 'teste');   // marcador de sistema instalado (a storage do teste é temporária)
        $this->app->useStoragePath($this->dir);
        config(['logging.channels.whatsapp.path' => $this->dir.'/logs/whatsapp.log', 'services.whatsapp.app_secret' => self::SECRET]);
        Log::forgetChannel('whatsapp');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function diag(): string
    {
        return implode("\n", array_map('file_get_contents', glob($this->dir.'/logs/whatsapp-*.log') ?: []));
    }

    private function contact($account): void
    {
        $this->inAccount($account, fn () => WhatsappContact::query()->create(['label' => 'Dono', 'phone' => self::PHONE, 'phone_hash' => WhatsappContact::hashPhone(self::PHONE),
            'phone_last4' => '4321', 'can_write' => true, 'active' => true, 'verified_at' => now()]));
    }

    private function body(string $text, string $wamid, string $from = '551187654321'): string
    {
        return json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '1029', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '15551234567', 'phone_number_id' => '111222333'],
            'contacts' => [['profile' => ['name' => 'Fulano'], 'wa_id' => $from]],
            'messages' => [['from' => $from, 'id' => $wamid, 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => $text]]]]]]]]]);
    }

    private function hit(string $body, ?string $signature = 'auto')
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($signature !== null) {
            $server['HTTP_X_HUB_SIGNATURE_256'] = $signature === 'auto' ? 'sha256='.hash_hmac('sha256', $body, self::SECRET) : $signature;
        }

        return $this->call('POST', '/webhooks/whatsapp', [], [], [], $server, $body);
    }

    public function test_cada_etapa_fica_registrada_sem_conteudo_privado(): void
    {
        [$a] = $this->makeClient('profissional');
        $this->contact($a);
        $wamid = 'wamid.HBgMNTUxMTg3NjU0MzIxFQIAEhgUM0EwRkI2QjQ4NjE2QUJGMUE5RTUA';
        $this->hit($this->body('100 reais de combustível secreto', $wamid))->assertOk();

        $log = $this->diag();
        foreach (['webhook.recebido', 'webhook.assinatura_ok', 'webhook.conteudo', '"mensagens":1', 'webhook.mensagem', '"resultado":"processed"', '"lancamento":true',
            'webhook.resposta', '"resultado":"aceita"', 'webhook.concluido'] as $needle) {
            $this->assertStringContainsString($needle, $log, $needle);
        }
        foreach (['combustível secreto', '87654321', $wamid, self::SECRET, 'Fulano'] as $private) {
            $this->assertStringNotContainsString($private, $log, 'diagnóstico não pode conter: '.$private);
        }
        $this->assertSame(1, WhatsappMessage::query()->where('direction', 'in')->where('status', 'processed')->count());
    }

    public function test_assinatura_recusada_informa_o_motivo(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $b = $this->body('oi', 'wamid.A1');
        $this->hit($b, null)->assertStatus(401);
        $this->hit($b, 'sha1=abc')->assertStatus(401);
        $this->hit($b, 'sha256='.hash_hmac('sha256', $b, 'outro-segredo'))->assertStatus(401);
        config(['services.whatsapp.app_secret' => '']);
        $this->hit($b)->assertStatus(401);

        $log = $this->diag();
        foreach (['"motivo":"sem_cabecalho"', '"motivo":"formato_invalido"', '"motivo":"nao_confere"', '"motivo":"segredo_vazio"'] as $m) {
            $this->assertStringContainsString($m, $log);
        }
        $this->assertSame(0, WhatsappMessage::query()->count());
    }

    public function test_erro_de_banco_nao_e_mais_silencioso(): void
    {
        [$a] = $this->makeClient('profissional');
        $this->contact($a);
        WhatsappMessage::creating(function () {
            throw new QueryException('mysql', 'insert into whatsapp_messages ...', [], new \PDOException('SQLSTATE[HY000]: General error: 1366 Incorrect string value', 0));
        });
        Log::spy();
        $this->hit($this->body('100 de combustível', 'wamid.B1'))->assertOk();
        WhatsappMessage::flushEventListeners();

        Log::shouldHaveReceived('channel')->with('whatsapp')->atLeast()->once();
        Log::shouldHaveReceived('error')->atLeast()->once();   // report() do erro, no log principal
        $this->assertSame(0, WhatsappMessage::query()->count());
    }

    public function test_erro_de_banco_aparece_no_diagnostico(): void
    {
        [$a] = $this->makeClient('profissional');
        $this->contact($a);
        WhatsappMessage::creating(function () {
            throw new QueryException('mysql', 'insert into whatsapp_messages ...', [], new \PDOException('SQLSTATE[HY000]: General error', 0));
        });
        $this->hit($this->body('100 de combustível', 'wamid.B2'))->assertOk();
        WhatsappMessage::flushEventListeners();
        $log = $this->diag();
        $this->assertStringContainsString('webhook.erro_banco', $log);
        $this->assertStringContainsString('"tabela":"whatsapp_messages"', $log);
        $this->assertStringContainsString('webhook.erro_processamento', $log);
    }

    public function test_duplicada_e_registrada_e_nao_reprocessa(): void
    {
        [$a] = $this->makeClient('profissional');
        $this->contact($a);
        $b = $this->body('100 de combustível', 'wamid.C1');
        $this->hit($b)->assertOk();
        $this->hit($b)->assertOk();
        $this->assertSame(1, WhatsappMessage::query()->where('direction', 'in')->count());
        $this->assertStringContainsString('webhook.duplicada', $this->diag());
    }

    public function test_corpo_sem_mensagens_e_avisado(): void
    {
        $this->hit(json_encode(['field' => 'messages', 'value' => ['messages' => [['id' => 'x', 'from' => '1', 'type' => 'text', 'text' => ['body' => 'oi']]]]]))->assertOk();
        $this->assertStringContainsString('webhook.sem_mensagens', $this->diag());
    }

    public function test_status_da_meta_atualiza_a_resposta_e_registra_falha(): void
    {
        [$a] = $this->makeClient('profissional');
        $this->contact($a);
        $this->hit($this->body('quanto gastei hoje?', 'wamid.D1'))->assertOk();
        $out = WhatsappMessage::query()->where('direction', 'out')->sole();
        $st = fn (string $status, array $errors = []) => json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp', 'statuses' => [['id' => $out->wamid, 'status' => $status, 'timestamp' => (string) time(), 'recipient_id' => '551187654321'] + ($errors ? ['errors' => $errors] : [])]]]]]]]);

        $this->hit($st('delivered'))->assertOk();
        $this->assertSame('delivered', $out->fresh()->status);
        $this->hit($st('read'))->assertOk();
        $this->hit($st('delivered'))->assertOk();   // fora de ordem: não regride
        $this->assertSame('read', $out->fresh()->status);
        $this->hit($st('failed', [['code' => 131047, 'title' => 'Re-engagement message']]))->assertOk();
        $this->assertSame('failed', $out->fresh()->status);
        $log = $this->diag();
        $this->assertStringContainsString('webhook.status_meta', $log);
        $this->assertStringContainsString('131047', $log);
    }

    public function test_envio_recusado_pela_meta_registra_codigo_sem_token(): void
    {
        [$a] = $this->makeClient('profissional');
        $this->contact($a);
        config(['services.whatsapp.mode' => 'cloud', 'services.whatsapp.access_token' => 'EAAG-token-de-teste-que-nao-pode-vazar', 'services.whatsapp.phone_number_id' => '111222333']);
        Http::fake(fn () => Http::response(['error' => ['message' => 'Error validating access token: Session has expired', 'code' => 190, 'error_subcode' => 463]], 401));
        $this->hit($this->body('quanto gastei hoje?', 'wamid.E1'))->assertOk();

        $this->assertSame('failed', WhatsappMessage::query()->where('direction', 'out')->value('status'));
        $log = $this->diag();
        $this->assertStringContainsString('"resultado":"falhou"', $log);
        $this->assertStringContainsString('"http":401', $log);
        $this->assertStringContainsString('"codigo":190', $log);
        $this->assertStringNotContainsString('EAAG-token', $log);
    }

    public function test_painel_mostra_diagnostico_sem_revelar_chaves(): void
    {
        [$a] = $this->makeClient('profissional');
        $this->contact($a);
        config(['services.whatsapp.access_token' => 'EAAG-segredo-do-token-'.str_repeat('x', 60), 'services.whatsapp.verify_token' => 'meu-token-de-verificacao']);
        $this->hit($this->body('100 de combustível', 'wamid.F1'))->assertOk();
        $owner = User::query()->create(['name' => 'Dono', 'email' => 'dono@teste.local', 'password' => 'Senha@Forte123', 'is_platform_admin' => true, 'status' => 'active']);

        $html = $this->actingAs($owner)->get('/admin/sistema')->assertOk()->assertSee('WhatsApp — diagnóstico')->assertSee('assinatura_ok')->assertSee('32 caracteres')->getContent();
        foreach ([self::SECRET, 'EAAG-segredo', 'meu-token-de-verificacao', '100 de combustível'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
    }

    public function test_configuracao_com_espaco_ou_valor_errado_e_apontada(): void
    {
        config(['services.whatsapp.app_secret' => ' '.self::SECRET.' ', 'services.whatsapp.phone_number_id' => '+55 11 98765-4321']);
        $r = collect(app(WhatsAppClient::class)->configReport())->keyBy('item');
        $this->assertFalse($r['App Secret (WHATSAPP_APP_SECRET)']['ok']);
        $this->assertStringContainsString('espaço', $r['App Secret (WHATSAPP_APP_SECRET)']['info']);
        $this->assertFalse($r['Phone Number ID (WHATSAPP_PHONE_NUMBER_ID)']['ok']);
    }
}
