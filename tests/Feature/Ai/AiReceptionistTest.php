<?php

namespace Tests\Feature\Ai;

use App\Modules\Ai\Models\AiConfig;
use App\Modules\Ai\Models\AiRequest;
use App\Modules\Ai\Models\AiSession;
use App\Modules\Ai\Models\AiToolCall;
use App\Modules\Ai\Providers\AgentResult;
use App\Modules\Ai\Providers\AiProviderException;
use App\Modules\Ai\Providers\ClaudeProvider;
use App\Modules\Ai\Providers\LlmProvider;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Models\MessagingChannel;
use App\Modules\Messaging\Models\StaffNotification;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Models\PatientConsent;
use App\Modules\Scheduling\Models\Appointment;
use Carbon\CarbonImmutable;
use Closure;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Scheduling\SchedulingSetup;
use Tests\TestCase;

class AiReceptionistTest extends TestCase
{
    use SchedulingSetup;

    private const SECRET = 'app-secret-de-teste';

    private const CPF = '52998224725';

    private MessagingChannel $channel;

    private Patient $pat;

    private int $wamid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScheduling();
        $this->channel = $this->tenant(fn () => MessagingChannel::create([
            'provider' => 'meta', 'mode' => 'test', 'name' => 'WhatsApp', 'phone_number_id' => '1098765432', 'api_version' => 'v21.0',
            'credentials' => ['access_token' => 'EAAG-token', 'app_secret' => self::SECRET], 'verify_token' => 'verifica-123',
        ]));
        $this->pat = $this->patient('Maria Paciente', ['whatsapp' => '11999990000', 'cpf' => self::CPF]);
        $this->tenant(fn () => PatientConsent::create(['patient_id' => $this->pat->id, 'purpose' => 'whatsapp_comunicacoes', 'term_version' => '1.0', 'granted' => true, 'channel' => 'presencial', 'recorded_by' => $this->clinic['admin']->id]));
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.(++$this->wamid)]]])]);
    }

    private function tenant(Closure $fn): mixed
    {
        return $this->context()->runFor($this->company()->id, $fn);
    }

    private function aiConfig(array $attrs = []): AiConfig
    {
        return $this->tenant(fn () => AiConfig::create(array_merge([
            'provider' => 'claude', 'api_key' => 'sk-ant-chave-de-teste-123456', 'assistant_name' => 'Aivi', 'is_active' => true,
            'settings' => ['whatsapp_enabled' => true, 'allow_booking' => true, 'allow_cancel' => true],
        ], $attrs)));
    }

    /** Provedor roteirizado: cada passo recebe o histórico e o executor de ferramentas. */
    private function script(array $steps): ScriptedProvider
    {
        $fake = new ScriptedProvider($steps);
        $this->app->instance(ClaudeProvider::class, $fake);

        return $fake;
    }

    private function webhook(array $payload): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', route('messaging.webhook', $this->channel->id), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET)], $body);
    }

    private function inbound(string $id, string $text, string $from = '5511999990000'): TestResponse
    {
        return $this->webhook(['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '1098765432'],
            'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Contato']]],
            'messages' => [['from' => $from, 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]]);
    }

    /** Textos livres enviados ao WhatsApp (respostas), na ordem. */
    private function replies(): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0]->data())->filter(fn ($b) => ($b['type'] ?? null) === 'text')->map(fn ($b) => $b['text']['body'])->values()->all();
    }

    private function aiSession(string $phone = '5511999990000'): ?AiSession
    {
        return $this->tenant(fn () => AiSession::query()->where('thread_id', MessageThread::query()->where('phone', $phone)->value('id'))->first());
    }

    public function test_books_in_two_steps_with_real_availability_and_logs_every_call(): void
    {
        $this->aiConfig();
        $other = $this->createClinic('Clínica Beta');
        $this->context()->runFor($other['company']->id, fn () => Doctor::create(['name' => 'Dr. Outra Clínica', 'crm' => '9999', 'crm_state' => 'SP']));
        $cardio = $this->tenant(fn () => Specialty::query()->where('name', 'Cardiologia')->value('id'));

        $fake = $this->script([
            function (array $history, Closure $execute) use ($cardio) {
                $this->assertSame([['role' => 'user', 'text' => 'Oi, quero marcar cardiologia']], $history);
                $this->assertSame(['Dra. Agenda'], array_column($execute('list_doctors', [])['doctors'], 'name')); // só a própria clínica
                $slots = $execute('find_available_slots', ['specialty_id' => $cardio])['slots'];
                $this->assertStringContainsString('08:00', $slots[0]['when']); // 06:00 + 2 h de antecedência mínima
                $proposal = $execute('propose_appointment', ['slot_id' => $slots[0]['slot_id'], 'payer_type' => 'private']);
                $this->assertStringContainsString('250,00', $proposal['proposal']['payment']);
                // Mesma resposta da proposta: a confirmação é recusada (precisa de nova mensagem do paciente).
                $this->assertStringContainsString('Aguarde', $execute('confirm_appointment', [])['error']);
                $this->assertSame(0, Appointment::query()->count());

                return new AgentResult('Tenho segunda às 08:00 com a Dra. Agenda, particular R$ 250,00. Confirma?', 'end_turn');
            },
            function (array $history, Closure $execute) {
                $this->assertSame(['user', 'assistant', 'user'], array_column($history, 'role'));
                $r = $execute('confirm_appointment', []);
                $this->assertTrue($r['booked']);

                return new AgentResult('Pronto! Consulta marcada. Protocolo *'.$r['protocol'].'*.', 'end_turn');
            },
        ]);

        $this->inbound('wamid.A1', 'Oi, quero marcar cardiologia')->assertOk();
        $this->travel(1)->minutes();
        $this->inbound('wamid.A2', 'Sim, pode confirmar')->assertOk();
        $this->inbound('wamid.A2', 'Sim, pode confirmar')->assertOk(); // reenvio do mesmo evento: ignorado

        $this->assertCount(2, $fake->calls);
        $this->assertStringContainsString('Clínica Alfa', $fake->calls[0]['system']);
        $this->assertStringContainsString('não dá diagnóstico', $fake->calls[0]['system']);
        $this->assertStringContainsString('Paciente identificado nesta conversa: Maria', $fake->calls[0]['context']);
        $this->assertContains('confirm_appointment', $fake->calls[0]['tools']);

        $appt = $this->tenant(fn () => Appointment::query()->sole());
        $this->assertSame([$this->pat->id, 'ai', 'scheduled', 'private'], [$appt->patient_id, $appt->channel, $appt->status, $appt->payer_type]);
        $this->assertTrue($appt->starts_at->eq(CarbonImmutable::parse($this->at('08:00'))));

        $replies = $this->replies();
        $this->assertCount(2, $replies);
        $this->assertStringStartsWith('🤖 *Aivi* — assistente virtual da Clínica Alfa', $replies[0]);
        $this->assertStringStartsWith('Pronto! Consulta marcada. Protocolo *'.$appt->protocol, $replies[1]);

        $session = $this->aiSession();
        $this->assertSame(['active', 2, 200, $this->pat->id], [$session->status, $session->replies, (int) $session->input_tokens, $session->patient_id]);
        $this->assertSame(2, $this->tenant(fn () => AiRequest::query()->count()));
        $this->assertSame(['list_doctors', 'find_available_slots', 'propose_appointment', 'confirm_appointment', 'confirm_appointment'],
            $this->tenant(fn () => AiToolCall::query()->orderBy('created_at')->orderBy('id')->pluck('tool')->all()));
        $this->assertSame(2, $this->tenant(fn () => Message::query()->where('purpose', 'ai_reply')->count()));
    }

    public function test_emergency_human_request_and_staff_takeover_bypass_the_model(): void
    {
        $this->aiConfig();
        $fake = $this->script([]);
        $admin = $this->clinic['admin'];

        $this->inbound('wamid.E1', 'Estou com DOR NO PEITO e falta de ar')->assertOk();
        $this->assertCount(0, $fake->calls);
        $this->assertStringContainsString('SAMU 192', $this->replies()[0]);
        $this->assertSame(['handoff', 'Possível emergência relatada pelo paciente'], [$this->aiSession()->status, $this->aiSession()->handoff_reason]);
        $this->assertSame('danger', $this->tenant(fn () => StaffNotification::query()->where('type', 'ai_handoff')->value('level')));

        // Com a equipe: a IA não responde; a recepção é avisada.
        $this->inbound('wamid.E2', 'Alguém aí?')->assertOk();
        $this->assertCount(1, $this->replies());
        $this->assertTrue($this->tenant(fn () => StaffNotification::query()->where('type', 'whatsapp_message')->exists()));

        // Devolvida à IA: volta a responder (sem repetir a apresentação).
        $thread = $this->tenant(fn () => MessageThread::query()->sole());
        $this->actingAs($admin)->post(route('messaging.threads.release', $thread))->assertRedirect();
        $fake->steps[] = fn () => new AgentResult('Nosso endereço está no cadastro da unidade.', 'end_turn');
        $this->travel(1)->minutes();
        $this->inbound('wamid.E3', 'Qual o endereço?')->assertOk();
        $this->assertSame('Nosso endereço está no cadastro da unidade.', $this->replies()[1]);

        // "atendente" → equipe, sem chamar o modelo.
        $this->travel(1)->minutes();
        $this->inbound('wamid.E4', 'quero falar com um ATENDENTE')->assertOk();
        $this->assertCount(1, $fake->calls);
        $this->assertSame('Paciente pediu atendimento humano', $this->aiSession()->handoff_reason);

        // Resposta manual da equipe também pausa a IA.
        $this->actingAs($admin)->post(route('messaging.threads.release', $thread))->assertRedirect();
        $this->actingAs($admin)->post(route('messaging.threads.reply', $thread), ['text' => 'Olá, aqui é a Ana da recepção.'])->assertRedirect();
        $this->assertSame('handoff', $this->aiSession()->status);
        $this->assertStringStartsWith('Assumida por', $this->aiSession()->handoff_reason);
        $this->actingAs($admin)->get(route('messaging.threads.show', $thread))->assertOk()->assertSee('Devolver à IA')->assertSee('Pausada');

        // Consentimento "atendimento por IA" revogado → equipe, sem IA.
        $this->tenant(fn () => PatientConsent::create(['patient_id' => $this->pat->id, 'purpose' => 'atendimento_ia', 'term_version' => '1.0', 'granted' => false, 'channel' => 'presencial', 'recorded_by' => $admin->id]));
        $this->actingAs($admin)->post(route('messaging.threads.release', $thread))->assertRedirect();
        $this->travel(1)->minutes();
        $this->inbound('wamid.E5', 'Bom dia')->assertOk();
        $this->assertCount(1, $fake->calls);
        $this->assertSame('Paciente não autorizou atendimento por assistente virtual', $this->aiSession()->handoff_reason);
    }

    public function test_patient_identification_is_required_masked_and_limited(): void
    {
        $this->aiConfig();
        $this->script([
            function (array $history, Closure $execute) {
                $this->assertStringContainsString('não identificado', $execute('my_appointments', [])['error']);
                foreach (range(1, 3) as $i) {
                    $this->assertFalse($execute('identify_patient', ['cpf' => '529.982.247-25', 'birth_date' => '1990-12-31'])['identified']);
                }
                $this->assertTrue($execute('identify_patient', ['cpf' => self::CPF, 'birth_date' => '1985-01-01'])['handed_off']);

                return new AgentResult('Vou passar para a equipe.', 'end_turn');
            },
            function (array $history, Closure $execute) {
                $r = $execute('identify_patient', ['cpf' => self::CPF, 'birth_date' => '1985-01-01']);
                $this->assertSame(['identified' => true, 'first_name' => 'Maria'], ['identified' => $r['identified'], 'first_name' => $r['patient']['first_name']]);
                $this->assertArrayHasKey('error', $execute('cancel_appointment', ['appointment_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ']));

                return new AgentResult('Identificada!', 'end_turn');
            },
            function (array $history, Closure $execute) {
                $r = $execute('register_patient', ['name' => 'Novo Paciente Teste', 'cpf' => '111.444.777-35', 'birth_date' => '1990-05-05']);
                $this->assertTrue($r['registered'] ?? false, json_encode($r));
                $this->assertStringContainsString('Já existe', $execute('register_patient', ['name' => 'Outro', 'cpf' => self::CPF, 'birth_date' => '1985-01-01'])['error']);

                return new AgentResult('Cadastro feito!', 'end_turn');
            },
        ]);

        // Telefone desconhecido: 3 erros de identificação → equipe.
        $this->inbound('wamid.I1', 'Quero ver minhas consultas', '5511955554444')->assertOk();
        $this->assertSame('handoff', $this->aiSession('5511955554444')->status);
        $logged = $this->tenant(fn () => AiToolCall::query()->where('tool', 'identify_patient')->get());
        $this->assertCount(4, $logged);
        $this->assertStringNotContainsString('52998224725', json_encode($logged->pluck('input')));
        $this->assertStringNotContainsString('1990-12-31', json_encode($logged->pluck('input')));

        // Outro telefone (não cadastrado): identificação correta vincula a conversa ao paciente.
        $this->inbound('wamid.I2', 'Meu CPF é 529.982.247-25', '5511933332222')->assertOk();
        $this->assertSame($this->pat->id, $this->aiSession('5511933332222')->patient_id);
        $this->assertSame($this->pat->id, $this->tenant(fn () => MessageThread::query()->where('phone', '5511933332222')->value('patient_id')));

        // Paciente novo: cadastro com o WhatsApp da conversa; CPF já existente não é recadastrado.
        $this->inbound('wamid.I3', 'Quero me cadastrar', '5511944443333')->assertOk();
        $novo = $this->tenant(fn () => Patient::query()->where('cpf', '11144477735')->sole());
        $this->assertSame(['11944443333', '1990-05-05'], [$novo->whatsapp, $novo->birth_date->toDateString()]);
    }

    public function test_provider_errors_and_refusals_fall_back_to_the_team(): void
    {
        $this->aiConfig();
        $this->script([
            fn () => throw new AiProviderException('Anthropic recusou a chamada: 529 overloaded'),
            fn () => new AgentResult('', 'refusal', refused: true),
        ]);

        $this->inbound('wamid.F1', 'Olá')->assertOk();
        $this->assertStringContainsString('não consegui concluir', $this->replies()[0]);
        $this->assertStringStartsWith('Falha na IA', $this->aiSession()->handoff_reason);

        $this->inbound('wamid.F2', 'Oi', '5511922221111')->assertOk();
        $this->assertSame('A IA recusou responder (política de segurança)', $this->aiSession('5511922221111')->handoff_reason);

        // IA desligada: nada de resposta automática, só aviso para a recepção.
        $this->tenant(fn () => AiConfig::query()->update(['is_active' => false]));
        $this->inbound('wamid.F3', 'Oi de novo', '5511911110000')->assertOk();
        $this->assertNull($this->aiSession('5511911110000'));
        $this->assertCount(2, $this->replies());
    }

    public function test_claude_provider_sends_cached_system_tools_effort_and_tool_results_through_the_sdk(): void
    {
        $this->aiConfig();
        $sent = [];
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => 'tool_use', 'stop_sequence' => null,
                'content' => [['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'list_specialties', 'input' => new \stdClass]],
                'usage' => ['input_tokens' => 1500, 'output_tokens' => 30, 'cache_creation_input_tokens' => 1400, 'cache_read_input_tokens' => 0],
            ])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 'msg_2', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => 'end_turn', 'stop_sequence' => null,
                'content' => [['type' => 'text', 'text' => 'Atendemos Cardiologia. Quer ver horários?']],
                'usage' => ['input_tokens' => 120, 'output_tokens' => 15, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 1400],
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($sent));
        $this->app->instance(ClaudeProvider::class, new ClaudeProvider(new Guzzle(['handler' => $stack])));

        $this->inbound('wamid.C1', 'Quais especialidades vocês têm?')->assertOk();

        $this->assertCount(2, $sent);
        $req = $sent[0]['request'];
        $body = json_decode((string) $req->getBody(), true);
        $this->assertSame('https://api.anthropic.com/v1/messages', explode('?', (string) $req->getUri())[0]);
        $this->assertSame('sk-ant-chave-de-teste-123456', $req->getHeaderLine('x-api-key'));
        $this->assertStringContainsString('server-side-fallback-2026-07-01', $req->getHeaderLine('anthropic-beta'));
        $this->assertSame(['claude-opus-5-5', 4096, 'low', 'default'], [$body['model'], $body['max_tokens'], $body['output_config']['effort'], $body['fallbacks']]);
        $this->assertArrayNotHasKey('thinking', $body);
        $this->assertArrayNotHasKey('tool_choice', $body);
        $this->assertSame(['type' => 'ephemeral'], $body['system'][0]['cache_control']);
        $this->assertArrayNotHasKey('cache_control', $body['system'][1]);
        $this->assertStringContainsString('Agora:', $body['system'][1]['text']);
        $this->assertSame('object', $body['tools'][0]['input_schema']['type']);
        $this->assertContains('propose_appointment', array_column($body['tools'], 'name'));
        $this->assertSame([['role' => 'user', 'content' => 'Quais especialidades vocês têm?']], $body['messages']);

        $second = json_decode((string) $sent[1]['request']->getBody(), true);
        $this->assertSame(['user', 'assistant', 'user'], array_column($second['messages'], 'role'));
        $this->assertSame('toolu_01', $second['messages'][2]['content'][0]['tool_use_id']);
        $this->assertStringContainsString('Cardiologia', $second['messages'][2]['content'][0]['content']);

        $this->assertStringEndsWith('Atendemos Cardiologia. Quer ver horários?', $this->replies()[0]);
        $this->assertSame([1500, 1400], $this->tenant(fn () => [(int) AiRequest::query()->sum('input_tokens') - 120, (int) AiRequest::query()->sum('cache_read_tokens')]));
    }

    public function test_openai_provider_uses_function_calling_with_clinic_model_and_key(): void
    {
        $this->aiConfig(['provider' => 'openai', 'model' => 'gpt-modelo-da-clinica', 'api_key' => 'sk-openai-chave-de-teste-123']);
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(['model' => 'gpt-modelo-da-clinica', 'choices' => [['finish_reason' => 'tool_calls', 'message' => ['role' => 'assistant', 'content' => null,
                'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'list_doctors', 'arguments' => '{}']]]]]], 'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 20]])
            ->push(['model' => 'gpt-modelo-da-clinica', 'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'Temos a Dra. Agenda (cardiologia).']]], 'usage' => ['prompt_tokens' => 950, 'completion_tokens' => 12]]),
        ]);

        $this->inbound('wamid.O1', 'Quem atende aí?')->assertOk();

        $calls = collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (HttpRequest $r) => str_contains($r->url(), 'openai'))->values();
        $this->assertCount(2, $calls);
        $this->assertSame('https://api.openai.com/v1/chat/completions', $calls[0]->url());
        $this->assertTrue($calls[0]->hasHeader('Authorization', 'Bearer sk-openai-chave-de-teste-123'));
        $this->assertSame(['gpt-modelo-da-clinica', 'system', 'function', 'auto'], [$calls[0]['model'], $calls[0]['messages'][0]['role'], $calls[0]['tools'][0]['type'], $calls[0]['tool_choice']]);
        $this->assertSame('object', $calls[0]['tools'][0]['function']['parameters']['type']);
        $tool = collect($calls[1]['messages'])->firstWhere('role', 'tool');
        $this->assertSame('call_1', $tool['tool_call_id']);
        $this->assertStringContainsString('Dra. Agenda', $tool['content']);
        $this->assertStringEndsWith('Temos a Dra. Agenda (cardiologia).', $this->replies()[0]);
        $this->assertSame(['openai', 1850], $this->tenant(fn () => [AiRequest::query()->value('provider'), (int) AiRequest::query()->sum('input_tokens')]));
    }

    public function test_settings_keep_key_encrypted_and_require_permission_and_mock_works_end_to_end(): void
    {
        $admin = $this->clinic['admin'];
        $this->actingAs($this->userWithRole($this->company(), 'recepcao'))->get(route('ai.settings'))->assertForbidden();
        $this->actingAs($admin)->get(route('ai.settings'))->assertOk()->assertSee('Recepcionista virtual');

        $form = ['provider' => 'openai', 'model' => '', 'assistant_name' => 'Aivi', 'effort' => 'low', 'max_replies_per_hour' => 30, 'max_daily_replies' => 500,
            'is_active' => 1, 'whatsapp_enabled' => 1, 'allow_booking' => 1, 'allow_cancel' => 1];
        $this->actingAs($admin)->put(route('ai.save'), $form)->assertSessionHasErrors('model');

        $key = 'sk-ant-api03-segredo-da-clinica-abcdef';
        $this->actingAs($admin)->put(route('ai.save'), ['provider' => 'claude', 'api_key' => $key] + $form)->assertSessionHasNoErrors();
        $raw = DB::table('ai_configs')->value('api_key');
        $this->assertNotSame($key, $raw);
        $this->assertSame($key, $this->tenant(fn () => AiConfig::query()->first()->apiKey()));
        $this->actingAs($admin)->get(route('ai.settings'))->assertOk()->assertDontSee($key)->assertSee('Chave própria configurada');
        $this->assertStringNotContainsString($key, DB::table('audit_logs')->pluck('new_values')->implode(' '));

        // Salvar sem chave mantém a atual.
        $this->actingAs($admin)->put(route('ai.save'), ['provider' => 'claude'] + $form)->assertSessionHasNoErrors();
        $this->assertSame($key, $this->tenant(fn () => AiConfig::query()->first()->apiKey()));

        // MOCK: teste de conexão e atendimento sem IA real.
        $this->actingAs($admin)->put(route('ai.save'), ['provider' => 'mock'] + $form)->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('ai.test'))->assertSessionHas('success');
        $this->inbound('wamid.M1', 'Quero agendar uma consulta')->assertOk();
        $this->assertStringContainsString('[MOCK] Posso ajudar a agendar. Médicos disponíveis: Dra. Agenda', $this->replies()[0]);
    }
}

/** Provedor de teste: passos roteirizados, registra o que recebeu. */
class ScriptedProvider implements LlmProvider
{
    public array $calls = [];

    public function __construct(public array $steps = []) {}

    public function run(AiConfig $config, string $staticSystem, string $dynamicContext, array $history, array $tools, callable $execute, callable $onRequest): AgentResult
    {
        $this->calls[] = ['system' => $staticSystem, 'context' => $dynamicContext, 'history' => $history, 'tools' => array_column($tools, 'name')];
        $onRequest(['provider' => 'claude', 'model' => 'claude-opus-5-5', 'input_tokens' => 100, 'output_tokens' => 20, 'stop_reason' => 'end_turn']);
        $step = array_shift($this->steps) ?? throw new \RuntimeException('IA chamada sem passo roteirizado');

        return $step($history, Closure::fromCallable($execute));
    }
}
