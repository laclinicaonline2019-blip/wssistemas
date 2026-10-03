<?php

namespace App\Modules\Messaging\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Jobs\AiReply;
use App\Modules\Ai\Jobs\ProcessInboundMedia;
use App\Modules\Ai\Services\AiReceptionist;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Models\MessagingChannel;
use App\Modules\Messaging\Providers\InboundEvent;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Company;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\AppointmentService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Webhook do WhatsApp.
 *
 * - Status (enviada/entregue/lida/falhou) atualiza a mensagem pelo ID do provedor.
 * - Mensagem do paciente: abre/atualiza a conversa (janela de 24 h), identifica o paciente pelo
 *   telefone e trata a resposta ao lembrete — botão (payload "CONFIRM:{agendamento}") ou texto
 *   1/2/3. Cancelamento respeita o prazo da clínica; "remarcar" vira aviso para a recepção.
 * - Mensagem livre: a recepcionista virtual (Fase 12) responde depois do 200 ao webhook; com a IA
 *   desligada ou a conversa com a equipe, vira aviso para a recepção.
 * - Idempotente: o mesmo ID de mensagem (wamid) nunca é processado duas vezes.
 */
class InboundService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly MessageService $messages,
        private readonly AppointmentService $appointments,
        private readonly NotificationCenter $notifications,
        private readonly AuditLogger $audit,
        private readonly AiReceptionist $ai,
    ) {}

    /** @return array{0: int, 1: mixed} status HTTP e corpo */
    public function handle(string $channelId, Request $request): array
    {
        $channel = $this->context->runAsSystem(fn () => MessagingChannel::query()->withoutGlobalScopes()->find($channelId));
        if (! $channel || ! $channel->is_active) {
            return [404, 'canal desconhecido'];
        }

        // Verificação do webhook (Meta): GET com hub.mode=subscribe e o token configurado.
        if ($request->isMethod('get')) {
            $ok = $request->query('hub_mode') === 'subscribe' && hash_equals((string) $channel->verify_token, (string) $request->query('hub_verify_token'));

            return $ok ? [200, (string) $request->query('hub_challenge')] : [403, 'token inválido'];
        }

        $provider = $this->messages->provider($channel);
        if (! $provider->verifySignature($channel, $request)) {
            Log::warning('Webhook do WhatsApp com assinatura inválida', ['channel' => $channel->id, 'ip' => $request->ip()]);

            return [401, 'assinatura inválida'];
        }

        $events = $provider->parseWebhook($request->json()->all());

        $this->context->runFor($channel->company_id, function () use ($channel, $events) {
            $channel->forceFill(['last_webhook_at' => now()])->saveQuietly();
            foreach ($events as $event) {
                if ($event->phoneNumberId && $channel->phone_number_id && $event->phoneNumberId !== $channel->phone_number_id) {
                    continue; // evento de outro número
                }
                $event->type === 'status' ? $this->status($event) : $this->message($channel, $event);
            }
        });

        return [200, 'ok'];
    }

    public function status(InboundEvent $e): void
    {
        $message = Message::query()->where('provider_message_id', $e->providerId)->first();
        if (! $message) {
            return;
        }

        // Status chegam fora de ordem: nunca "volta" de lida para entregue.
        $rank = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];
        if (($rank[$e->status] ?? -1) <= ($rank[$message->status] ?? -1) && $e->status !== 'failed') {
            return;
        }

        $message->forceFill(match ($e->status) {
            'delivered' => ['status' => 'delivered', 'delivered_at' => now()],
            'read' => ['status' => 'read', 'read_at' => now(), 'delivered_at' => $message->delivered_at ?? now()],
            'failed' => ['status' => 'failed', 'error' => mb_substr((string) $e->error, 0, 500)],
            default => ['status' => 'sent'],
        })->save();

        if ($e->status === 'failed') {
            $this->notifications->notify('message_failed', 'WhatsApp não entregue', $message->purposeLabel().': '.$e->error,
                $message->thread_id ? route('messaging.threads.show', $message->thread_id) : null, $message->branch_id, 'ia.conversas', 'warning');
        }
    }

    public function message(MessagingChannel $channel, InboundEvent $e): ?Message
    {
        $phone = Phone::e164($e->from) ?? preg_replace('/\D/', '', (string) $e->from);
        $patient = $this->matchPatient($phone);
        $thread = $this->messages->thread($channel, $phone, $patient, $e->contactName);

        try {
            $message = DB::transaction(fn () => Message::create([
                'channel' => 'whatsapp', 'channel_id' => $channel->id, 'thread_id' => $thread->id, 'patient_id' => $thread->patient_id,
                'direction' => 'in', 'purpose' => 'inbound', 'recipient' => $phone, 'body' => mb_substr((string) $e->text, 0, 4096),
                'params' => $e->media ? ['media' => array_diff_key($e->media, ['data' => true])] : null,
                'status' => 'received', 'provider_message_id' => $e->providerId ?: null,
            ]));
        } catch (QueryException) {
            return null; // reenvio do mesmo evento
        }

        $thread->forceFill([
            'last_inbound_at' => now(), 'last_message_at' => now(), 'unread_count' => $thread->unread_count + 1, 'status' => 'open',
            'contact_name' => $thread->contact_name ?: $e->contactName,
        ])->save();

        // Áudio, imagem ou documento: baixa, transcreve/lê e só então a IA (ou a equipe) responde.
        if ($e->media) {
            ProcessInboundMedia::dispatchAfterResponse($channel->company_id, $message->id, $e->media);

            return $message;
        }

        [$intent, $appointment] = $this->intent($thread, $e);
        if ($appointment) {
            $message->forceFill(['appointment_id' => $appointment->id])->save();
        }

        match ($intent) {
            'CONFIRM' => $this->confirm($thread, $appointment),
            'CANCEL' => $this->cancel($thread, $appointment),
            'RESCHEDULE' => $this->reschedule($thread, $appointment),
            default => $this->ai->accepts($thread)
                ? AiReply::dispatchAfterResponse($channel->company_id, $thread->id, $message->id)
                : $this->notifications->notify('whatsapp_message', 'Nova mensagem no WhatsApp',
                    ($thread->patient?->displayName() ?? $thread->contact_name ?? '+'.$phone).': '.mb_substr((string) $e->text, 0, 160),
                    route('messaging.threads.show', $thread), null, 'ia.conversas'),
        };

        return $message;
    }

    /** @return array{0: ?string, 1: ?Appointment} */
    private function intent(MessageThread $thread, InboundEvent $e): array
    {
        // Botão do modelo: "CONFIRM:{id do agendamento}".
        if ($e->buttonPayload && preg_match('/^(CONFIRM|CANCEL|RESCHEDULE):([0-9A-Za-z]{26})$/', $e->buttonPayload, $m)) {
            $appointment = Appointment::query()->with('patient:id,whatsapp,phone')->find($m[2]);
            // O botão só vale para o próprio paciente (mesmo cadastro ou mesmo telefone).
            $owner = $appointment && ($appointment->patient_id === $thread->patient_id
                || in_array(Phone::key($thread->phone), [Phone::key($appointment->patient->whatsapp), Phone::key($appointment->patient->phone)], true));

            return $owner ? [$m[1], $appointment] : [null, null];
        }

        // Texto: só vale como resposta se houve lembrete recente nesta conversa.
        $text = mb_strtolower(trim((string) $e->text));
        $intent = collect(config('messaging.keywords'))->search(fn ($words) => in_array($text, $words, true));
        if (! $intent) {
            return [null, null];
        }

        $reminder = Message::query()->where('thread_id', $thread->id)->where('direction', 'out')->where('purpose', 'reminder')
            ->whereNotNull('appointment_id')->where('created_at', '>=', now()->subDays(3))->latest()->first();
        $appointment = $reminder ? Appointment::query()->find($reminder->appointment_id) : null;

        return $appointment && $appointment->starts_at->isFuture() ? [$intent, $appointment] : [null, null];
    }

    private function confirm(MessageThread $thread, Appointment $a): void
    {
        if ($a->status === 'confirmed') {
            $this->messages->autoReply($thread, 'Sua presença já estava confirmada. Até lá!', $a->id);

            return;
        }
        try {
            $this->appointments->confirm(null, $a, 'whatsapp');
            $this->messages->autoReply($thread, 'Presença confirmada! Obrigado. Até '.$this->when($a).'.', $a->id);
        } catch (BusinessRuleViolation) {
            $this->messages->autoReply($thread, 'Não foi possível confirmar este agendamento. Nossa equipe vai falar com você.', $a->id);
            $this->notify($thread, $a, 'Confirmação pelo WhatsApp não aplicada', 'warning');
        }
    }

    private function cancel(MessageThread $thread, Appointment $a): void
    {
        $hours = (int) Company::query()->whereKey($a->company_id)->first()?->setting('messaging.cancel_min_hours', 2);

        if (! in_array($a->status, ['scheduled', 'confirmed'], true)) {
            $this->messages->autoReply($thread, 'Este agendamento já não está ativo.', $a->id);

            return;
        }
        if ($a->starts_at->lt(now()->addHours($hours))) {
            $this->messages->autoReply($thread, "Cancelamentos pelo WhatsApp são aceitos até {$hours} h antes. Nossa equipe vai entrar em contato.", $a->id);
            $this->notify($thread, $a, 'Paciente pediu cancelamento fora do prazo', 'warning');

            return;
        }

        $this->appointments->cancel(null, $a, 'Cancelado pelo paciente via WhatsApp', notify: false);
        $this->messages->autoReply($thread, 'Consulta de '.$this->when($a).' cancelada. Se quiser remarcar, responda esta mensagem.', $a->id);
        $this->notify($thread, $a, 'Consulta cancelada pelo paciente (WhatsApp)');
    }

    private function reschedule(MessageThread $thread, Appointment $a): void
    {
        $this->messages->autoReply($thread, 'Certo! Nossa equipe vai falar com você para escolher um novo horário. Se preferir, remarque pelo portal do paciente.', $a->id);
        $this->notify($thread, $a, 'Paciente pediu para remarcar (WhatsApp)', 'warning');
    }

    private function notify(MessageThread $thread, Appointment $a, string $title, string $level = 'info'): void
    {
        $this->notifications->notify('appointment_whatsapp', $title, ($thread->patient?->displayName() ?? '+'.$thread->phone).' · '.$this->when($a).' · protocolo '.$a->protocol,
            route('messaging.threads.show', $thread), $a->branch_id, 'agenda.visualizar', $level);
        $this->audit->record('messaging.'.str($title)->slug('_'), $a, metadata: ['thread_id' => $thread->id]);
    }

    private function when(Appointment $a): string
    {
        $a->loadMissing('branch:id,timezone');

        return $a->starts_at->timezone($a->branch->timezone ?: 'America/Sao_Paulo')->format('d/m \à\s H:i');
    }

    /** Paciente pelo telefone (DDD + 8 últimos dígitos). Mais de um com o mesmo número: não vincula. */
    private function matchPatient(string $phone): ?Patient
    {
        $key = Phone::key($phone);
        if (! $key) {
            return null;
        }
        $last8 = substr($key, -8);
        $matches = Patient::query()->whereNull('anonymized_at')
            ->where(fn ($q) => $q->where('whatsapp', 'like', '%'.$last8)->orWhere('phone', 'like', '%'.$last8))
            ->get(['id', 'whatsapp', 'phone', 'name', 'social_name'])
            ->filter(fn ($p) => Phone::key($p->whatsapp) === $key || Phone::key($p->phone) === $key);

        return $matches->count() === 1 ? Patient::query()->find($matches->first()->id) : null;
    }
}
