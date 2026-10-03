<?php

namespace Tests\Feature\Ai;

use App\Modules\Ai\Models\AiConfig;
use App\Modules\Ai\Models\AiMedia;
use App\Modules\Ai\Providers\AgentResult;
use App\Modules\Ai\Providers\ClaudeProvider;
use App\Modules\Documents\Models\PatientFile;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Models\MessagingChannel;
use App\Modules\Messaging\Models\StaffNotification;
use App\Modules\Patients\Models\Patient;
use Closure;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Scheduling\SchedulingSetup;
use Tests\TestCase;

/** Fase 13 — áudio, imagem e PDF recebidos no WhatsApp: transcrição, leitura (OCR) e conferência humana. */
class MediaTest extends TestCase
{
    use SchedulingSetup;

    private const SECRET = 'app-secret-de-teste';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private MessagingChannel $channel;

    private Patient $pat;

    private int $out = 0;

    /** Conteúdo devolvido pelo CDN da Meta, por ID de mídia. */
    private array $cdn = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpScheduling();
        $this->channel = $this->tenant(fn () => MessagingChannel::create([
            'provider' => 'meta', 'mode' => 'test', 'name' => 'WhatsApp', 'phone_number_id' => '1098765432', 'api_version' => 'v21.0',
            'credentials' => ['access_token' => 'EAAG-token', 'app_secret' => self::SECRET], 'verify_token' => 'verifica-123',
        ]));
        $this->pat = $this->patient('Maria Paciente', ['whatsapp' => '11999990000']);
        Http::fake(function (HttpRequest $r) {
            if (preg_match('#graph\.facebook\.com/v21\.0/(MEDIA\w+)$#', $r->url(), $m)) {
                return isset($this->cdn[$m[1]]) ? Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp/'.$m[1], 'mime_type' => $this->cdn[$m[1]][1]])
                    : Http::response(['error' => ['message' => 'not found']], 404);
            }
            if (preg_match('#lookaside\.fbsbx\.com/whatsapp/(MEDIA\w+)$#', $r->url(), $m)) {
                return $r->hasHeader('Authorization', 'Bearer EAAG-token') ? Http::response($this->cdn[$m[1]][0]) : Http::response('', 401);
            }
            if (str_contains($r->url(), 'graph.facebook.com')) {
                return Http::response(['messages' => [['id' => 'wamid.OUT'.(++$this->out)]]]);
            }
            if (str_contains($r->url(), 'api.openai.com/v1/audio/transcriptions')) {
                return Http::response(['text' => $this->transcript]);
            }
            if (str_contains($r->url(), 'cdn.z-api.io')) {
                return Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png']);
            }

            return Http::response('não esperado: '.$r->url(), 500);
        });
    }

    private string $transcript = '';

    private function tenant(Closure $fn): mixed
    {
        return $this->context()->runFor($this->company()->id, $fn);
    }

    private function aiConfig(array $attrs = []): AiConfig
    {
        return $this->tenant(fn () => AiConfig::create(array_merge([
            'provider' => 'claude', 'api_key' => 'sk-ant-chave-de-teste-123456', 'assistant_name' => 'Aivi', 'is_active' => true,
            'settings' => ['whatsapp_enabled' => true, 'media_enabled' => true, 'audio_enabled' => true],
        ], $attrs)));
    }

    private function inboundMedia(string $id, string $type, string $mediaId, ?string $caption = null): TestResponse
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '1098765432'], 'contacts' => [['wa_id' => '5511999990000', 'profile' => ['name' => 'Maria']]],
            'messages' => [['from' => '5511999990000', 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => $type,
                $type => array_filter(['id' => $mediaId, 'mime_type' => 'x/declarado', 'caption' => $caption, 'voice' => $type === 'audio' ? true : null])]],
        ]]]]]]);

        return $this->call('POST', route('messaging.webhook', $this->channel->id), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET)], $body);
    }

    private function replies(): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (HttpRequest $r) => str_contains($r->url(), '/messages') && ($r['type'] ?? null) === 'text')
            ->map(fn (HttpRequest $r) => $r['text']['body'])->values()->all();
    }

    public function test_image_is_downloaded_read_by_claude_with_structured_output_and_answered_as_unverified(): void
    {
        $this->aiConfig();
        $this->cdn['MEDIA1'] = [base64_decode(self::PNG), 'image/png'];
        $sent = [];
        $extraction = ['doc_type' => 'exam_request', 'summary' => 'Pedido de exames, Dra. Silva, 01/10/2026', 'patient_name' => 'Maria Paciente', 'document_date' => '2026-10-01',
            'professional_name' => 'Dra. Silva', 'professional_registry' => 'CRM-SP 12345', 'medications' => [], 'exams' => [['name' => 'Hemograma completo', 'code' => ''], ['name' => 'Glicemia de jejum', 'code' => '']],
            'payment' => ['amount' => '', 'paid_at' => '', 'payer_name' => '', 'receiver_name' => '', 'method' => '', 'transaction_id' => ''],
            'legibility' => 'good', 'uncertain_fields' => [], 'raw_text' => 'Solicito: Hemograma completo; Glicemia de jejum.'];
        $msg = fn (string $stop, array $content) => new Response(200, ['Content-Type' => 'application/json'], json_encode(['id' => 'msg', 'type' => 'message', 'role' => 'assistant',
            'model' => 'claude-opus-5-5', 'stop_reason' => $stop, 'stop_sequence' => null, 'content' => $content,
            'usage' => ['input_tokens' => 1600, 'output_tokens' => 300, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0]]));
        $stack = HandlerStack::create(new MockHandler([
            $msg('end_turn', [['type' => 'text', 'text' => json_encode($extraction)]]),
            $msg('end_turn', [['type' => 'text', 'text' => 'Recebi seu pedido de exames (a equipe vai conferir). Quer agendar ou só registrar?']]),
        ]));
        $stack->push(Middleware::history($sent));
        $this->app->instance(ClaudeProvider::class, new ClaudeProvider(new Guzzle(['handler' => $stack])));

        $this->inboundMedia('wamid.IMG1', 'image', 'MEDIA1', 'meu pedido')->assertOk();

        // 1) Leitura: bloco de imagem + structured outputs (schema fechado), regras de só transcrever.
        $read = json_decode((string) $sent[0]['request']->getBody(), true);
        $this->assertSame(['image', 'base64', 'image/png'], [$read['messages'][0]['content'][0]['type'], $read['messages'][0]['content'][0]['source']['type'], $read['messages'][0]['content'][0]['source']['media_type']]);
        $this->assertSame(base64_encode(base64_decode(self::PNG)), $read['messages'][0]['content'][0]['source']['data']);
        $this->assertSame('json_schema', $read['output_config']['format']['type']);
        $this->assertFalse($read['output_config']['format']['schema']['additionalProperties']);
        $this->assertStringContainsString('Não corrija, não complete e não deduza', is_string($read['system']) ? $read['system'] : json_encode($read['system'], JSON_UNESCAPED_UNICODE));

        // 2) Guardado em disco privado, "não verificado", equipe avisada.
        $media = $this->tenant(fn () => AiMedia::query()->sole());
        $this->assertSame(['processed', 'exam_request', 'pending', 'image', 'image/png', $this->pat->id, 'meu pedido'],
            [$media->status, $media->doc_type, $media->review_status, $media->kind, $media->mime, $media->patient_id, $media->caption]);
        Storage::disk('local')->assertExists($media->path);
        $this->assertStringStartsWith('companies/'.$this->company()->id.'/messaging-media/', $media->path);
        $this->assertSame('Documento recebido pelo WhatsApp — conferir', $this->tenant(fn () => StaffNotification::query()->where('type', 'ai_media')->value('title')));

        // 3) A conversa recebe o resumo NÃO VERIFICADO e a IA responde com ele no histórico.
        $body = $this->tenant(fn () => Message::query()->where('direction', 'in')->value('body'));
        $this->assertStringContainsString('"Pedido de exame" (NÃO VERIFICADO): Hemograma completo; Glicemia de jejum', $body);
        $chat = json_decode((string) $sent[1]['request']->getBody(), true);
        $this->assertSame($body, $chat['messages'][0]['content']);
        $this->assertStringContainsString('NÃO VERIFICADA', $chat['system'][0]['text']);
        $this->assertStringEndsWith('Quer agendar ou só registrar?', $this->replies()[0]);
    }

    public function test_audio_is_transcribed_by_openai_and_emergency_rules_still_apply(): void
    {
        $this->aiConfig();
        $this->tenant(fn () => AiConfig::query()->first()->forceFill(['transcription_api_key' => 'sk-openai-audio-123456789012'])->save());
        $ogg = "OggS\x00\x02".str_repeat("\x00", 20)."\x01\x13OpusHead\x01\x01\x38\x01\x80\xbb\x00\x00\x00\x00\x00";
        $this->cdn['MEDIAA1'] = [$ogg, 'audio/ogg; codecs=opus'];
        $this->cdn['MEDIAA2'] = [$ogg, 'audio/ogg; codecs=opus'];
        $fake = new ScriptedProvider([function (array $history) {
            $this->assertSame('🎤 Áudio (transcrição automática): Quero marcar uma consulta com cardiologista', $history[0]['text']);

            return new AgentResult('Claro! Temos a Dra. Agenda. Quer ver horários?', 'end_turn');
        }]);
        $this->app->instance(ClaudeProvider::class, $fake);

        $this->transcript = 'Quero marcar uma consulta com cardiologista';
        $this->inboundMedia('wamid.AUD1', 'audio', 'MEDIAA1')->assertOk();
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'audio/transcriptions') && $r->hasHeader('Authorization', 'Bearer sk-openai-audio-123456789012')
            && collect($r->data())->contains(fn ($p) => ($p['name'] ?? null) === 'model' && $p['contents'] === 'whisper-1')
            && collect($r->data())->contains(fn ($p) => ($p['name'] ?? null) === 'language' && $p['contents'] === 'pt'));
        $this->assertCount(1, $fake->calls);
        $this->assertSame('Claro! Temos a Dra. Agenda. Quer ver horários?', explode("\n\n", $this->replies()[0])[1]);
        $this->assertSame(['processed', 'audio'], $this->tenant(fn () => [AiMedia::query()->value('status'), AiMedia::query()->value('kind')]));
        $this->assertSame(0, $this->tenant(fn () => StaffNotification::query()->where('type', 'ai_media')->count())); // áudio não vai para a conferência

        // Áudio com sinal de emergência: SAMU, sem chamar o modelo.
        $this->travel(1)->minutes();
        $this->transcript = 'Minha mãe está com dor no peito e falta de ar';
        $this->inboundMedia('wamid.AUD2', 'audio', 'MEDIAA2')->assertOk();
        $this->assertCount(1, $fake->calls);
        $this->assertStringContainsString('SAMU 192', $this->replies()[1]);
    }

    public function test_review_attach_discard_receipts_failures_permissions_and_reading_a_patient_file(): void
    {
        $this->aiConfig();
        $admin = $this->clinic['admin'];
        $fake = new ScriptedProvider;
        $fake->extract = fn () => ['doc_type' => 'payment_receipt', 'summary' => 'Comprovante PIX', 'patient_name' => '', 'document_date' => '', 'professional_name' => '',
            'professional_registry' => '', 'medications' => [], 'exams' => [], 'legibility' => 'good', 'uncertain_fields' => ['transaction_id'], 'raw_text' => 'PIX R$ 250,00',
            'payment' => ['amount' => '250,00', 'paid_at' => '2026-10-05 07:10', 'payer_name' => 'Maria', 'receiver_name' => 'Clínica Alfa', 'method' => 'PIX', 'transaction_id' => '']];
        $fake->steps = [fn () => new AgentResult('Recebi o comprovante; a equipe vai conferir.', 'end_turn'), fn () => new AgentResult('Não consegui abrir o arquivo, pode reenviar?', 'end_turn')];
        $this->app->instance(ClaudeProvider::class, $fake);

        // Comprovante: aviso de conferência; nenhuma baixa financeira.
        $this->cdn['MEDIAR1'] = [base64_decode(self::PNG), 'image/png'];
        $this->inboundMedia('wamid.R1', 'image', 'MEDIAR1')->assertOk();
        $media = $this->tenant(fn () => AiMedia::query()->sole());
        $this->assertSame(['payment_receipt', 'warning'], [$media->doc_type, $this->tenant(fn () => StaffNotification::query()->where('type', 'ai_media')->value('level'))]);
        $this->assertSame(0, $this->tenant(fn () => Receivable::query()->where('status', 'paid')->count()));

        // Download que falha (mídia expirada): registrado como falha, conversa segue.
        $this->travel(1)->minutes();
        $this->inboundMedia('wamid.R2', 'image', 'MEDIAX')->assertOk();
        $failed = $this->tenant(fn () => AiMedia::query()->where('status', 'failed')->sole());
        $this->assertStringStartsWith('Download:', $failed->error);
        $this->assertSame('[imagem recebida]', $this->tenant(fn () => Message::query()->where('provider_message_id', 'wamid.R2')->value('body')));

        // Permissões: médico não acessa a conferência.
        $this->actingAs($this->userWithRole($this->company(), 'medico'))->get(route('ai.media.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('ai.media.index'))->assertOk()->assertSee('Comprovante de pagamento');
        $this->actingAs($admin)->get(route('ai.media.show', $media))->assertOk()->assertSee('NÃO VERIFICADA')->assertSee('250,00')->assertSee('não</strong> dá baixa', false);
        $file = $this->actingAs($admin)->get(route('ai.media.file', $media));
        $file->assertOk();
        $this->assertStringContainsString('no-store', $file->headers->get('Cache-Control'));
        $this->assertTrue(DB::table('audit_logs')->where('action', 'ai.media_downloaded')->exists());

        // Anexar à ficha (paciente da conversa) e conferir; descartar exige motivo.
        $this->actingAs($admin)->post(route('ai.media.attach', $media), ['category' => 'other', 'title' => 'Comprovante PIX'])->assertSessionHasNoErrors();
        $pf = $this->tenant(fn () => PatientFile::query()->sole());
        $this->assertSame([$this->pat->id, 'image/png', $pf->id], [$pf->patient_id, $pf->mime, $media->fresh()->patient_file_id]);
        $this->actingAs($admin)->post(route('ai.media.attach', $media), ['category' => 'other', 'title' => 'De novo'])->assertSessionHas('error');
        $this->actingAs($admin)->post(route('ai.media.verify', $media), ['notes' => 'Conferido no extrato'])->assertSessionHasNoErrors();
        $this->assertSame(['verified', $admin->id], [$media->fresh()->review_status, $media->fresh()->reviewed_by]);
        $this->actingAs($admin)->post(route('ai.media.discard', $failed), [])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post(route('ai.media.discard', $failed), ['reason' => 'Mídia expirada'])->assertSessionHasNoErrors();
        $this->assertSame('discarded', $failed->fresh()->review_status);

        // "Ler com IA" num arquivo da ficha (Fase 6).
        $fake->extract = fn (string $bytes, string $mime) => ['doc_type' => 'prescription', 'summary' => 'Receita', 'patient_name' => '', 'document_date' => '', 'professional_name' => '',
            'professional_registry' => '', 'exams' => [], 'legibility' => 'partial', 'uncertain_fields' => ['posologia'], 'raw_text' => 'Losartana 50 mg',
            'medications' => [['name' => 'Losartana', 'concentration' => '50 mg', 'form' => 'comprimido', 'dosage_instructions' => '', 'quantity' => '']],
            'payment' => ['amount' => '', 'paid_at' => '', 'payer_name' => '', 'receiver_name' => '', 'method' => '', 'transaction_id' => '']];
        $this->actingAs($admin)->post(route('patient_files.ai_read', $pf))->assertRedirect();
        $read = $this->tenant(fn () => AiMedia::query()->where('source', 'upload')->sole());
        $this->assertSame(['processed', 'prescription', $pf->id, $pf->path], [$read->status, $read->doc_type, $read->patient_file_id, $read->path]);
        $this->actingAs($admin)->get(route('ai.media.show', $read))->assertOk()->assertSee('Losartana')->assertSee('posologia');

        // IA desligada: não lê.
        $this->tenant(fn () => AiConfig::query()->update(['is_active' => false]));
        $this->actingAs($admin)->post(route('patient_files.ai_read', $pf))->assertSessionHas('error');
    }

    public function test_unsupported_file_and_unofficial_providers_media(): void
    {
        $this->aiConfig(['provider' => 'mock']);
        $this->cdn['MEDIAT'] = ['apenas texto', 'text/plain'];
        $this->inboundMedia('wamid.T1', 'document', 'MEDIAT')->assertOk();
        $this->assertStringContainsString('Formato de arquivo não aceito', $this->tenant(fn () => AiMedia::query()->value('error')));

        // Z-API: imagem por URL temporária (https) — lida pelo MOCK.
        $zchannel = $this->tenant(function () {
            $c = MessagingChannel::query()->sole();
            $c->forceFill(['provider' => 'zapi', 'mode' => 'production', 'credentials' => ['zapi_instance_id' => 'ABCDEF123456', 'zapi_token' => 'TOKEN123456']])->save();

            return $c;
        });
        $this->postJson(route('messaging.webhook', ['channel' => $zchannel->id, 'token' => 'verifica-123']), [
            'type' => 'ReceivedCallback', 'phone' => '5511999990000', 'messageId' => 'ZIMG1', 'fromMe' => false, 'momment' => now()->getTimestampMs(),
            'image' => ['imageUrl' => 'https://cdn.z-api.io/img/abc.png', 'mimeType' => 'image/png', 'caption' => 'pedido'],
        ])->assertOk();
        $z = $this->tenant(fn () => AiMedia::query()->where('mime', 'image/png')->sole());
        $this->assertSame(['processed', 'exam_request', 'mock'], [$z->status, $z->doc_type, $z->provider]);
        $this->assertStringContainsString('[MOCK] Hemograma completo', $this->tenant(fn () => Message::query()->where('provider_message_id', 'ZIMG1')->value('body')));
        $this->assertSame(1, $this->tenant(fn () => MessageThread::query()->count()));
    }
}
