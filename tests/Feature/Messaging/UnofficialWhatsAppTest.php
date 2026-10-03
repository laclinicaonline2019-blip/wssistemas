<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Models\MessagingChannel;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Models\PatientConsent;
use App\Modules\Scheduling\Models\Appointment;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Scheduling\SchedulingSetup;
use Tests\TestCase;

/** WhatsApp NÃO OFICIAL (Z-API e Evolution API), escolhido pela clínica com aceite de risco. */
class UnofficialWhatsAppTest extends TestCase
{
    use SchedulingSetup;

    private Patient $pat;

    private array $tokens = [];

    private int $seq = 0;

    private array $form = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScheduling();
        $this->pat = $this->patient('Maria Paciente', ['whatsapp' => '11999990000']);
        $this->tenant(fn () => PatientConsent::create(['patient_id' => $this->pat->id, 'purpose' => 'whatsapp_comunicacoes', 'term_version' => '1.0', 'granted' => true, 'channel' => 'presencial', 'recorded_by' => $this->clinic['admin']->id]));
        Http::fake([
            'api.z-api.io/*/status' => Http::response(['connected' => false, 'error' => 'You are not connected.', 'smartphoneConnected' => false]),
            'api.z-api.io/*' => fn () => Http::response(['zaapId' => 'Z'.(++$this->seq), 'messageId' => 'ZMSG'.$this->seq, 'id' => 'ZMSG'.$this->seq]),
            'evolution.clinica.test/instance/connectionState/*' => Http::response(['instance' => ['instanceName' => 'clinica', 'state' => 'open']]),
            'evolution.clinica.test/*' => fn () => Http::response(['key' => ['remoteJid' => '5511999990000@s.whatsapp.net', 'fromMe' => true, 'id' => 'BAE5'.(++$this->seq)], 'status' => 'PENDING'], 201),
        ]);
        $this->form = ['name' => 'WhatsApp da clínica', 'is_active' => 1];
    }

    private function tenant(Closure $fn): mixed
    {
        return $this->context()->runFor($this->company()->id, $fn);
    }

    private function book(string $time, string $date = self::MONDAY): string
    {
        $admin = $this->clinic['admin'];
        $this->app['auth']->forgetGuards();
        $this->tokens[$admin->id] ??= $this->apiToken($admin);
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->tokens[$admin->id])->postJson('/api/v1/appointments', $this->bookPayload($this->pat, $time, ['starts_at' => $this->at($time, $date)]))->assertCreated()->json('data.id');
    }

    private function channel(): MessagingChannel
    {
        return $this->tenant(fn () => MessagingChannel::query()->sole());
    }

    private function hook(array $payload, ?string $token = null): TestResponse
    {
        $channel = $this->channel();
        $this->flushHeaders();

        return $this->postJson(route('messaging.webhook', ['channel' => $channel->id, 'token' => $token ?? $channel->verify_token]), $payload);
    }

    public function test_zapi_requires_risk_acceptance_sends_text_and_handles_replies_and_status(): void
    {
        $admin = $this->clinic['admin'];
        $zapi = $this->form + ['provider' => 'zapi', 'zapi_instance_id' => '3C1A2B3C4D5E6F', 'zapi_token' => 'F1E2D3C4B5A6', 'zapi_client_token' => 'seguranca-123'];

        // Sem aceite do risco: não salva.
        $this->actingAs($admin)->put(route('messaging.channel.save'), $zapi)->assertSessionHasErrors('accept_risk');
        $this->assertSame(0, $this->tenant(fn () => MessagingChannel::query()->count()));

        $this->actingAs($admin)->put(route('messaging.channel.save'), $zapi + ['accept_risk' => 1])->assertSessionHasNoErrors();
        $channel = $this->channel();
        $this->assertSame(['zapi', 'production', $admin->id], [$channel->provider, $channel->mode, $channel->risk_accepted_by]);
        $this->assertNotNull($channel->risk_accepted_at);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'messaging.unofficial_risk_accepted')->exists());
        $this->assertStringNotContainsString('F1E2D3C4B5A6', (string) DB::table('messaging_channels')->value('credentials')); // criptografado
        $this->actingAs($admin)->get(route('messaging.settings'))->assertOk()->assertSee('NÃO OFICIAL')->assertSee('token='.$channel->verify_token, false)
            ->assertDontSee('F1E2D3C4B5A6')->assertDontSee('seguranca-123');

        // Mensagens da clínica vão como TEXTO (sem modelo da Meta).
        $id = $this->book('09:00', '2026-10-12');
        $this->travelTo(CarbonImmutable::parse('2026-10-11 10:00', 'America/Sao_Paulo'));
        $this->artisan('aivexa:messaging:run')->assertSuccessful();
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.z-api.io/instances/3C1A2B3C4D5E6F/token/F1E2D3C4B5A6/send-text'
            && $r->hasHeader('Client-Token', 'seguranca-123') && $r['phone'] === '5511999990000' && str_contains($r['message'], 'Responda: 1 para CONFIRMAR'));
        $reminder = $this->tenant(fn () => Message::query()->where('purpose', 'reminder')->sole());
        $this->assertSame(['sent', 'ZMSG2'], [$reminder->status, $reminder->provider_message_id]);

        // Webhook sem token / token errado: recusado.
        $this->hook(['type' => 'ReceivedCallback'], 'errado')->assertUnauthorized();

        // Grupo e mensagem enviada pelo próprio número: ignorados. Paciente responde "1": confirma.
        $base = ['type' => 'ReceivedCallback', 'phone' => '5511999990000', 'senderName' => 'Maria', 'momment' => now()->getTimestampMs(), 'status' => 'RECEIVED'];
        $this->hook($base + ['messageId' => 'G1', 'isGroup' => true, 'text' => ['message' => '2']])->assertOk();
        $this->hook($base + ['messageId' => 'M0', 'fromMe' => true, 'text' => ['message' => '2']])->assertOk();
        $this->hook($base + ['messageId' => 'IN1', 'isGroup' => false, 'fromMe' => false, 'text' => ['message' => '1']])->assertOk();
        $this->hook($base + ['messageId' => 'IN1', 'text' => ['message' => '1']])->assertOk(); // repetido
        $this->assertSame('confirmed', $this->tenant(fn () => Appointment::query()->findOrFail($id)->status));
        $this->assertSame(1, $this->tenant(fn () => Message::query()->where('direction', 'in')->count()));
        Http::assertSent(fn (HttpRequest $r) => str_contains((string) ($r['message'] ?? ''), 'Presença confirmada'));

        // Status: lida.
        $this->hook(['type' => 'MessageStatusCallback', 'status' => 'READ', 'ids' => ['ZMSG2'], 'phone' => '5511999990000', 'momment' => 1])->assertOk();
        $this->assertSame('read', $reminder->fresh()->status);

        // Sem janela de 24 h: a equipe responde mesmo dias depois.
        $this->travel(3)->days();
        $thread = $this->tenant(fn () => MessageThread::query()->sole());
        $this->actingAs($admin)->post(route('messaging.threads.reply', $thread), ['text' => 'Bom dia, Maria!'])->assertSessionHasNoErrors();
        Http::assertSent(fn (HttpRequest $r) => ($r['message'] ?? null) === 'Bom dia, Maria!');

        // Conexão: QR Code não lido.
        $this->actingAs($admin)->post(route('messaging.channel.check'))->assertSessionHas('error', fn ($m) => str_contains($m, 'DESCONECTADA'));

        // Voltar para a API oficial limpa o aceite.
        $this->actingAs($admin)->put(route('messaging.channel.save'), $this->form + ['provider' => 'meta', 'mode' => 'test', 'phone_number_id' => '1098765432', 'api_version' => 'v21.0'])->assertSessionHasNoErrors();
        $this->assertNull($this->channel()->risk_accepted_at);
    }

    public function test_evolution_api_sends_with_apikey_parses_upsert_and_update_and_checks_connection(): void
    {
        $admin = $this->clinic['admin'];
        $this->actingAs($admin)->put(route('messaging.channel.save'), $this->form + ['provider' => 'evolution', 'evolution_url' => 'http://evolution.clinica.test',
            'evolution_instance' => 'clinica', 'evolution_api_key' => 'chave-evolution', 'accept_risk' => 1])->assertSessionHasErrors('evolution_url'); // só https
        $this->actingAs($admin)->put(route('messaging.channel.save'), $this->form + ['provider' => 'evolution', 'evolution_url' => 'https://evolution.clinica.test/',
            'evolution_instance' => 'clinica', 'evolution_api_key' => 'chave-evolution', 'accept_risk' => 1])->assertSessionHasNoErrors();

        $this->book('08:00');
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://evolution.clinica.test/message/sendText/clinica' && $r->hasHeader('apikey', 'chave-evolution')
            && $r['number'] === '5511999990000' && str_contains($r['text'], 'Sua consulta foi agendada'));
        $sent = $this->tenant(fn () => Message::query()->where('purpose', 'booking_confirmation')->sole());
        $this->assertSame(['sent', 'BAE51'], [$sent->status, $sent->provider_message_id]);

        // Recebida (texto livre → conversa e aviso), grupo ignorado; atualização de status.
        $this->hook(['event' => 'messages.upsert', 'instance' => 'clinica', 'data' => ['key' => ['remoteJid' => '120363@g.us', 'fromMe' => false, 'id' => 'GRP1'],
            'message' => ['conversation' => 'oi grupo'], 'messageType' => 'conversation']])->assertOk();
        $this->hook(['event' => 'messages.upsert', 'instance' => 'clinica', 'data' => ['key' => ['remoteJid' => '5511999990000@s.whatsapp.net', 'fromMe' => false, 'id' => 'EVIN1'],
            'pushName' => 'Maria', 'message' => ['extendedTextMessage' => ['text' => 'Posso levar acompanhante?']], 'messageType' => 'extendedTextMessage', 'messageTimestamp' => now()->timestamp]])->assertOk();
        $in = $this->tenant(fn () => Message::query()->where('direction', 'in')->sole());
        $this->assertSame(['Posso levar acompanhante?', $this->pat->id], [$in->body, $in->patient_id]);

        $this->hook(['event' => 'messages.update', 'instance' => 'clinica', 'data' => ['keyId' => 'BAE51', 'remoteJid' => '5511999990000@s.whatsapp.net', 'fromMe' => true, 'status' => 'DELIVERY_ACK']])->assertOk();
        $this->assertSame('delivered', $sent->fresh()->status);

        $this->actingAs($admin)->post(route('messaging.channel.check'))->assertSessionHas('success', 'Instância conectada.');
    }
}
