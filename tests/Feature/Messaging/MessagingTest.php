<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Mail\PatientMessageMail;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Models\MessagingChannel;
use App\Modules\Messaging\Models\StaffNotification;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Models\PatientConsent;
use App\Modules\Platform\Models\Company;
use App\Modules\Scheduling\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Scheduling\SchedulingSetup;
use Tests\TestCase;

class MessagingTest extends TestCase
{
    use SchedulingSetup;

    private const SECRET = 'app-secret-de-teste';

    private array $tokens = [];

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
        $this->pat = $this->patient('Maria Paciente', ['whatsapp' => '11999990000', 'email' => 'maria@example.test']);
        $this->consent($this->pat, 'whatsapp_comunicacoes');
        Http::fake(['graph.facebook.com/*' => fn () => $this->responses
            ? Http::response(...array_shift($this->responses))
            : Http::response(['messages' => [['id' => 'wamid.OUT'.(++$this->wamid)]]])]);
    }

    /** Próximas respostas da API da Meta (corpo, status); depois volta ao sucesso padrão. */
    private array $responses = [];

    private function tenant(\Closure $fn): mixed
    {
        return $this->context()->runFor($this->company()->id, $fn);
    }

    private function as($user): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->tokens[$user->id] ??= $this->apiToken($user);
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->tokens[$user->id]);
    }

    private function consent(Patient $p, string $purpose, bool $granted = true): void
    {
        $this->tenant(fn () => PatientConsent::create(['patient_id' => $p->id, 'purpose' => $purpose, 'term_version' => '1.0', 'granted' => $granted, 'channel' => 'presencial', 'recorded_by' => $this->clinic['admin']->id]));
    }

    private function book(Patient $p, string $time, string $date = self::MONDAY): string
    {
        return $this->as($this->clinic['admin'])->postJson('/api/v1/appointments', $this->bookPayload($p, $time, ['starts_at' => $this->at($time, $date)]))->assertCreated()->json('data.id');
    }

    private function webhook(array $payload, ?string $secret = self::SECRET): TestResponse
    {
        $body = json_encode($payload);
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($secret) {
            $headers['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', route('messaging.webhook', $this->channel->id), [], [], [], $headers, $body);
    }

    private function inbound(string $id, ?string $text = null, ?string $payload = null, string $from = '5511999990000'): TestResponse
    {
        $m = ['from' => $from, 'id' => $id, 'timestamp' => (string) now()->timestamp];
        $m += $payload ? ['type' => 'button', 'button' => ['payload' => $payload, 'text' => $text ?? 'Confirmar']] : ['type' => 'text', 'text' => ['body' => $text]];

        return $this->webhook(['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '1098765432'],
            'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Maria']]], 'messages' => [$m],
        ]]]]]]);
    }

    private function sentBodies(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0]->data())->values()->all();
    }

    public function test_booking_sends_whatsapp_template_with_consent_and_falls_back_or_skips_without_it(): void
    {
        $this->book($this->pat, '08:00');

        $msg = $this->tenant(fn () => Message::query()->where('purpose', 'booking_confirmation')->sole());
        $this->assertSame(['whatsapp', 'sent', '5511999990000', 'aivexa_agendamento', 'wamid.OUT1'], [$msg->channel, $msg->status, $msg->recipient, $msg->template, $msg->provider_message_id]);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://graph.facebook.com/v21.0/1098765432/messages'
            && $r->hasHeader('Authorization', 'Bearer EAAG-token')
            && $r['template']['name'] === 'aivexa_agendamento' && $r['template']['language']['code'] === 'pt_BR'
            && array_column($r['template']['components'][0]['parameters'], 'text') === ['Maria', '05/10/2026', '08:00', 'Dra. Agenda', $this->branch()->name, $msg->params['protocolo']]);

        // Sem consentimento de WhatsApp, com consentimento de e-mail → e-mail.
        Mail::fake();
        $joao = $this->patient('João Paciente', ['whatsapp' => '11988887777', 'email' => 'joao@example.test']);
        $this->consent($joao, 'email_comunicacoes');
        $this->book($joao, '08:30');
        Mail::assertSent(PatientMessageMail::class, fn ($m) => $m->hasTo('joao@example.test') && str_contains($m->text, '08:30'));

        // Sem consentimento algum → registrada como não enviada, com o motivo (nada sai).
        $ana = $this->patient('Ana Sem Consentimento', ['whatsapp' => '11977776666']);
        $this->book($ana, '09:00');
        $skipped = $this->tenant(fn () => Message::query()->where('patient_id', $ana->id)->sole());
        $this->assertSame('skipped', $skipped->status);
        $this->assertStringContainsString('consentimento', $skipped->error);
        Http::assertSentCount(1);

        // Revogação de consentimento vale para os próximos envios.
        $this->consent($this->pat, 'whatsapp_comunicacoes', false);
        $this->as($this->clinic['admin'])->postJson('/api/v1/appointments/'.$this->tenant(fn () => Appointment::query()->where('patient_id', $this->pat->id)->value('id')).'/cancel', ['reason' => 'Paciente pediu por telefone'])->assertOk();
        $this->assertSame('skipped', $this->tenant(fn () => Message::query()->where('purpose', 'cancellation')->value('status')));
    }

    public function test_reminders_respect_offsets_dedupe_and_creation_time_with_quick_reply_buttons(): void
    {
        // Agendamento para a próxima segunda, criado com antecedência.
        $id = $this->book($this->pat, '09:00', '2026-10-12');
        $this->travelTo(CarbonImmutable::parse('2026-10-11 09:30', 'America/Sao_Paulo')); // 23h30 antes
        $this->artisan('aivexa:messaging:run')->assertSuccessful();
        $this->artisan('aivexa:messaging:run')->assertSuccessful(); // não duplica

        $reminders = $this->tenant(fn () => Message::query()->where('purpose', 'reminder')->get());
        $this->assertCount(1, $reminders);
        $this->assertStringStartsWith('reminder:24:', $reminders[0]->dedupe_key);
        Http::assertSent(fn (HttpRequest $r) => ($r['template']['name'] ?? null) === 'aivexa_lembrete'
            && $r['template']['components'][1] == ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => '0', 'parameters' => [['type' => 'payload', 'payload' => 'CONFIRM:'.$id]]]
            && $r['template']['components'][2]['parameters'][0]['payload'] === 'CANCEL:'.$id);

        // Marcado em cima da hora: não recebe o lembrete de 24 h, só o de 2 h.
        $this->tokens = []; // tokens da API expiram com a viagem no tempo
        $late = $this->patient('Pedro Encaixe', ['whatsapp' => '11966665555']);
        $this->consent($late, 'whatsapp_comunicacoes');
        $lateId = $this->book($late, '08:00', '2026-10-12');
        $this->travelTo(CarbonImmutable::parse('2026-10-12 06:30', 'America/Sao_Paulo'));
        $this->artisan('aivexa:messaging:run');
        $this->assertSame(['reminder:2:'.$lateId], $this->tenant(fn () => Message::query()->where('purpose', 'reminder')->where('appointment_id', $lateId)->pluck('dedupe_key')->map(fn ($k) => substr($k, 0, strlen('reminder:2:'.$lateId)))->all()));

        // Lembretes desativados pela clínica.
        $this->tenant(fn () => Company::query()->whereKey($this->company()->id)->update(['settings' => json_encode(['messaging' => ['reminders_enabled' => false]])]));
        $before = $this->tenant(fn () => Message::query()->count());
        $this->travelTo(CarbonImmutable::parse('2026-10-12 07:30', 'America/Sao_Paulo'));
        $this->artisan('aivexa:messaging:run');
        $this->assertSame($before, $this->tenant(fn () => Message::query()->count()));
    }

    public function test_webhook_verification_signature_status_and_reply_to_reminder(): void
    {
        $id = $this->book($this->pat, '09:00', '2026-10-12');
        $this->travelTo(CarbonImmutable::parse('2026-10-11 10:00', 'America/Sao_Paulo'));
        $this->artisan('aivexa:messaging:run');
        $reminder = $this->tenant(fn () => Message::query()->where('purpose', 'reminder')->sole());

        // Verificação GET (hub.*) e assinatura obrigatória no POST.
        $this->get(route('messaging.webhook', $this->channel->id).'?hub.mode=subscribe&hub.verify_token=verifica-123&hub.challenge=987')->assertOk()->assertSeeText('987');
        $this->get(route('messaging.webhook', $this->channel->id).'?hub.mode=subscribe&hub.verify_token=errado&hub.challenge=987')->assertForbidden();
        $this->inbound('wamid.IN1', '1')->assertOk();
        $this->webhook(['entry' => []], null)->assertUnauthorized();
        $this->webhook(['entry' => []], 'outro-segredo')->assertUnauthorized();

        // Status: entregue → lida (fora de ordem não volta).
        $status = fn (string $s) => $this->webhook(['entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => '1098765432'], 'statuses' => [['id' => $reminder->provider_message_id, 'status' => $s, 'timestamp' => '1']]]]]]]]);
        $status('read')->assertOk();
        $status('delivered')->assertOk();
        $this->assertSame('read', $reminder->fresh()->status);

        // "1" depois do lembrete confirmou a consulta e respondeu dentro da janela.
        $appt = $this->tenant(fn () => Appointment::query()->findOrFail($id));
        $this->assertSame('confirmed', $appt->status);
        $this->assertTrue(collect($this->sentBodies())->contains(fn ($b) => ($b['type'] ?? null) === 'text' && str_contains($b['text']['body'], 'Presença confirmada')));
        // Mesmo evento reenviado pela Meta não é processado de novo.
        $count = $this->tenant(fn () => Message::query()->count());
        $this->inbound('wamid.IN1', '1')->assertOk();
        $this->assertSame($count, $this->tenant(fn () => Message::query()->count()));
        $this->assertSame(1, $this->tenant(fn () => MessageThread::query()->where('phone', '5511999990000')->value('unread_count')));
    }

    public function test_cancel_button_respects_deadline_and_free_text_goes_to_reception(): void
    {
        $id = $this->book($this->pat, '09:00', '2026-10-12');
        $other = $this->book($this->patient('Outro', ['whatsapp' => '11955554444']), '09:30', '2026-10-12');

        // Botão de outro paciente (payload adulterado) não vale.
        $this->inbound('wamid.X1', 'Cancelar', 'CANCEL:'.$other)->assertOk();
        $this->assertSame('scheduled', $this->tenant(fn () => Appointment::query()->findOrFail($other)->status));

        // Dentro do prazo (padrão 2 h): cancela, sem mandar também o modelo de cancelamento.
        $this->inbound('wamid.X2', 'Cancelar', 'CANCEL:'.$id)->assertOk();
        $appt = $this->tenant(fn () => Appointment::query()->findOrFail($id));
        $this->assertSame(['cancelled', null], [$appt->status, $appt->cancelled_by]);
        $this->assertSame(0, $this->tenant(fn () => Message::query()->where('purpose', 'cancellation')->where('appointment_id', $id)->count()));
        $this->assertSame(1, $this->tenant(fn () => StaffNotification::query()->where('title', 'Consulta cancelada pelo paciente (WhatsApp)')->count()));

        // Fora do prazo: não cancela, avisa a equipe.
        $soon = $this->book($this->pat, '08:00');
        $this->travelTo(CarbonImmutable::parse(self::MONDAY.' 07:00', 'America/Sao_Paulo'));
        $this->inbound('wamid.X3', 'Cancelar', 'CANCEL:'.$soon)->assertOk();
        $this->assertSame('scheduled', $this->tenant(fn () => Appointment::query()->findOrFail($soon)->status));
        $this->assertSame(1, $this->tenant(fn () => StaffNotification::query()->where('title', 'Paciente pediu cancelamento fora do prazo')->count()));

        // Texto livre de número desconhecido → conversa + aviso para a recepção.
        $this->inbound('wamid.X4', 'Bom dia, vocês atendem pelo convênio X?', null, '5521988887777')->assertOk();
        $thread = $this->tenant(fn () => MessageThread::query()->where('phone', '5521988887777')->sole());
        $this->assertNull($thread->patient_id);
        $admin = $this->clinic['admin'];
        $this->actingAs($admin)->get(route('messaging.inbox'))->assertOk()->assertSee('+5521988887777');
        $this->actingAs($admin)->get(route('messaging.threads.show', $thread))->assertOk()->assertSee('convênio X');
        $this->actingAs($admin)->get(route('notifications.index'))->assertOk()->assertSee('Nova mensagem no WhatsApp');

        // Resposta da equipe dentro da janela; fora da janela é recusada.
        $this->actingAs($admin)->post(route('messaging.threads.reply', $thread), ['text' => 'Atendemos sim!'])->assertSessionHas('success');
        Http::assertSent(fn (HttpRequest $r) => ($r['text']['body'] ?? null) === 'Atendemos sim!' && $r['to'] === '5521988887777');
        $this->travel(25)->hours();
        $this->actingAs($admin)->post(route('messaging.threads.reply', $thread), ['text' => 'Ainda aí?'])->assertSessionHas('error');
    }

    public function test_failures_retry_with_backoff_and_permanent_errors_notify_team(): void
    {
        $this->responses = [[['error' => ['code' => 1, 'message' => 'temporário']], 500], [['messages' => [['id' => 'wamid.RETRY']]], 200],
            [['error' => ['code' => 132001, 'message' => 'Template name does not exist']], 400]];

        $this->book($this->pat, '09:30'); // sem lembrete devido no período do teste
        $msg = $this->tenant(fn () => Message::query()->where('purpose', 'booking_confirmation')->sole());
        $this->assertSame(['queued', 1], [$msg->status, $msg->attempts]);
        $this->assertNotNull($msg->next_attempt_at);

        $this->travel(5)->minutes();
        $this->artisan('aivexa:messaging:run');
        $this->assertSame(['sent', 'wamid.RETRY', 2], [$msg->fresh()->status, $msg->fresh()->provider_message_id, $msg->fresh()->attempts]);

        $this->book($this->patient('Outra', ['whatsapp' => '11944443333']), '08:30');
        $this->consent($this->tenant(fn () => Patient::query()->where('whatsapp', '11944443333')->first()), 'whatsapp_comunicacoes');
        $this->as($this->clinic['admin'])->postJson('/api/v1/appointments/'.$this->tenant(fn () => Appointment::query()->whereHas('patient', fn ($q) => $q->where('whatsapp', '11944443333'))->value('id')).'/cancel', ['reason' => 'Cancelado pela clínica'])->assertOk();
        $failed = $this->tenant(fn () => Message::query()->where('purpose', 'cancellation')->sole());
        $this->assertSame('failed', $failed->status);
        $this->assertStringContainsString('132001', $failed->error);
        $this->assertSame(1, $this->tenant(fn () => StaffNotification::query()->where('type', 'message_failed')->count()));
    }

    public function test_settings_mock_simulation_permissions_and_isolation(): void
    {
        $admin = $this->clinic['admin'];
        $this->actingAs($admin)->get(route('messaging.settings'))->assertOk()->assertSee($this->channel->webhookUrl())->assertDontSee('EAAG-token')->assertDontSee(self::SECRET);

        // Troca para MOCK; credenciais não são apagadas quando o campo vem vazio.
        $this->actingAs($admin)->put(route('messaging.channel.save'), ['provider' => 'mock', 'mode' => 'test', 'name' => 'Demo', 'api_version' => 'v21.0', 'is_active' => '1'])->assertSessionHas('success');
        $channel = $this->channel->fresh();
        $this->assertSame(['mock', 'mock', 'EAAG-token'], [$channel->provider, $channel->mode, $channel->credential('access_token')]);
        $this->assertNotSame('EAAG-token', DB::table('messaging_channels')->value('credentials')); // criptografado
        $this->actingAs($admin)->put(route('messaging.automation.save'), ['reminder_hours' => '48, 3', 'cancel_min_hours' => 6, 'reminders_enabled' => '1', 'on_booking' => '1', 'whatsapp_enabled' => '1', 'require_consent' => '1'])
            ->assertSessionHas('success');
        $this->assertSame([48, 3], $this->company()->fresh()->setting('messaging.reminder_hours'));

        // MOCK: nada vai para a Meta; resposta simulada confirma a consulta pelo botão.
        Http::fake();
        $id = $this->book($this->pat, '09:00', '2026-10-12');
        $thread = $this->tenant(fn () => MessageThread::query()->where('phone', '5511999990000')->sole());
        $this->actingAs($admin)->post(route('messaging.threads.simulate', $thread), ['payload' => 'CONFIRM:'.$id])->assertSessionHas('success');
        $this->assertSame('confirmed', $this->tenant(fn () => Appointment::query()->findOrFail($id)->status));
        Http::assertNothingSent();

        // Link do portal enviado pelo WhatsApp da clínica (modelo "portal_access").
        $this->actingAs($admin)->post(route('patients.portal.link', $this->pat), ['send_whatsapp' => '1'])->assertSessionHas('portal_link');
        $portal = $this->tenant(fn () => Message::query()->where('purpose', 'portal_access')->sole());
        $this->assertSame('sent', $portal->status);
        $this->assertStringContainsString('/portal/', $portal->params['link']);

        // Permissões e isolamento.
        $doctor = $this->userWithRole($this->company(), 'medico');
        $this->actingAs($doctor)->get(route('messaging.inbox'))->assertForbidden();
        $this->actingAs($doctor)->get(route('messaging.settings'))->assertForbidden();
        $reception = $this->userWithRole($this->company(), 'recepcao');
        $this->actingAs($reception)->get(route('messaging.threads.show', $thread))->assertOk();
        $beta = $this->createClinic('Clínica Beta');
        $this->actingAs($beta['admin'])->get(route('messaging.threads.show', $thread->id))->assertNotFound();
        $this->post(route('messaging.webhook', '01ZZZZZZZZZZZZZZZZZZZZZZZZ'))->assertNotFound();
    }
}
