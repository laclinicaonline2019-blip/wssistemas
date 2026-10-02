<?php

namespace App\Modules\Messaging\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Core\Tenancy\TenantContext;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Jobs\SendMessage;
use App\Modules\Messaging\Mail\PatientMessageMail;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Models\MessagingChannel;
use App\Modules\Messaging\Providers\MessagingException;
use App\Modules\Messaging\Providers\MetaCloudProvider;
use App\Modules\Messaging\Providers\MockWhatsAppProvider;
use App\Modules\Messaging\Providers\WhatsAppProvider;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Company;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Caixa de saída de mensagens ao paciente.
 *
 * - Canal: WhatsApp (se a clínica tem número ativo, o paciente tem WhatsApp e — por padrão —
 *   CONSENTIU receber pelo WhatsApp); senão e-mail (com consentimento de e-mail); senão a
 *   mensagem fica registrada como "não enviada" com o motivo.
 * - Deduplicação: a mesma chave (ex.: lembrete 24 h do agendamento X) nunca gera duas mensagens.
 * - Envio em fila (SendMessage) depois do commit; falha temporária → nova tentativa com espera
 *   crescente; falha definitiva → aviso para a equipe.
 */
class MessageService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly NotificationCenter $notifications,
    ) {}

    public function activeChannel(): ?MessagingChannel
    {
        return MessagingChannel::query()->where('is_active', true)->latest()->first();
    }

    public function provider(MessagingChannel $channel): WhatsAppProvider
    {
        return $channel->isMock() ? app(MockWhatsAppProvider::class) : app(MetaCloudProvider::class);
    }

    /** Monta o texto da finalidade a partir dos parâmetros (mesma ordem do modelo da Meta). */
    public function render(string $purpose, array $params): string
    {
        $text = (string) config("messaging.purposes.{$purpose}.text");

        return preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) ($params[$m[1]] ?? ''), $text);
    }

    /**
     * Enfileira uma mensagem automática ao paciente. Retorna null quando a chave já foi usada.
     */
    public function queueForPatient(string $purpose, Patient $patient, array $params, ?Appointment $appointment = null, ?string $dedupeKey = null, ?User $actor = null): ?Message
    {
        $company = Company::query()->findOrFail($this->context->companyId());
        $requireConsent = (bool) $company->setting('messaging.require_consent', true);
        $channel = $this->activeChannel();
        $phone = Phone::e164($patient->whatsapp ?: $patient->phone);
        $data = [
            'branch_id' => $appointment?->branch_id, 'patient_id' => $patient->id, 'appointment_id' => $appointment?->id,
            'direction' => 'out', 'purpose' => $purpose, 'params' => $params, 'body' => $this->render($purpose, $params),
            'dedupe_key' => $dedupeKey, 'created_by' => $actor?->id,
        ];

        if ($patient->isAnonymized()) {
            return null;
        }

        if ($channel && $phone && $company->setting('messaging.whatsapp_enabled', true) && (! $requireConsent || $patient->hasConsent('whatsapp_comunicacoes'))) {
            $tpl = $channel->template($purpose);
            $data += ['channel' => 'whatsapp', 'channel_id' => $channel->id, 'recipient' => $phone, 'template' => $tpl['name'],
                'thread_id' => $this->thread($channel, $phone, $patient)->id];
        } elseif ($patient->email && $company->setting('messaging.email_enabled', true) && (! $requireConsent || $patient->hasConsent('email_comunicacoes'))) {
            $data += ['channel' => 'email', 'recipient' => mb_strtolower($patient->email)];
        } else {
            $reason = match (true) {
                ! $phone && ! $patient->email => 'Paciente sem WhatsApp/telefone e sem e-mail.',
                $requireConsent => 'Sem consentimento do paciente para WhatsApp/e-mail (LGPD) ou canal não configurado.',
                default => 'Nenhum canal de envio configurado.',
            };
            $data += ['channel' => $phone ? 'whatsapp' : 'email', 'recipient' => $phone ?? $patient->email, 'status' => 'skipped', 'error' => $reason];
        }

        try {
            $message = DB::transaction(fn () => Message::create($data)); // savepoint: duplicidade não invalida a transação (PostgreSQL)
        } catch (QueryException $e) {
            if ($dedupeKey && Message::query()->where('dedupe_key', $dedupeKey)->exists()) {
                return null;
            }
            throw $e;
        }

        if ($message->status === 'queued') {
            $companyId = $this->context->companyId();
            DB::afterCommit(fn () => SendMessage::dispatch($companyId, $message->id));
        }

        return $message;
    }

    /** Mensagem digitada pela equipe na conversa (texto livre: só com a janela de 24 h aberta). */
    public function sendManual(User $actor, MessageThread $thread, string $text): Message
    {
        if (! $thread->windowOpen()) {
            throw new BusinessRuleViolation('Fora da janela de 24 h do WhatsApp: o paciente não escreveu nas últimas 24 horas. Use um modelo aprovado (ex.: lembrete) ou aguarde o contato.', 'whatsapp_window_closed');
        }

        $message = Message::create([
            'channel' => 'whatsapp', 'channel_id' => $thread->channel_id, 'thread_id' => $thread->id, 'patient_id' => $thread->patient_id,
            'direction' => 'out', 'purpose' => 'manual', 'recipient' => $thread->phone, 'body' => mb_substr($text, 0, 4096), 'created_by' => $actor->id,
        ]);
        $this->deliver($message);
        $this->audit->record('messaging.manual_sent', $message, metadata: ['thread_id' => $thread->id]);

        return $message->refresh();
    }

    /** Resposta automática (dentro da janela aberta pela mensagem do paciente). */
    public function autoReply(MessageThread $thread, string $text, ?string $appointmentId = null): Message
    {
        $message = Message::create([
            'channel' => 'whatsapp', 'channel_id' => $thread->channel_id, 'thread_id' => $thread->id, 'patient_id' => $thread->patient_id,
            'appointment_id' => $appointmentId, 'direction' => 'out', 'purpose' => 'auto_reply', 'recipient' => $thread->phone, 'body' => $text,
        ]);
        $this->deliver($message);

        return $message;
    }

    public function deliverById(string $id): void
    {
        $message = Message::query()->find($id);
        if ($message && $message->status === 'queued') {
            $this->deliver($message);
        }
    }

    public function deliver(Message $message): void
    {
        // Lock: o cron de reenvio e o worker não enviam a mesma mensagem duas vezes.
        $locked = Message::query()->whereKey($message->id)->where('status', 'queued')
            ->update(['attempts' => DB::raw('attempts + 1'), 'next_attempt_at' => now()->addMinutes(10), 'updated_at' => now()]);
        if ($locked === 0) {
            return;
        }
        $message->refresh();

        try {
            $providerId = $message->channel === 'email' ? $this->sendEmail($message) : $this->sendWhatsApp($message);
            $message->forceFill(['status' => 'sent', 'provider_message_id' => $providerId, 'sent_at' => now(), 'error' => null, 'next_attempt_at' => null])->save();
            if ($message->thread_id) {
                MessageThread::query()->whereKey($message->thread_id)->update(['last_message_at' => now(), 'updated_at' => now()]);
            }
        } catch (Throwable $e) {
            $retryable = ! ($e instanceof MessagingException) || $e->retryable;
            $final = ! $retryable || $message->attempts >= (int) config('messaging.max_attempts');
            $message->forceFill([
                'status' => $final ? 'failed' : 'queued', 'error' => mb_substr($e->getMessage(), 0, 500),
                'next_attempt_at' => $final ? null : now()->addMinutes(2 ** $message->attempts),
            ])->save();

            if ($final && $message->purpose !== 'manual') {
                $this->notifications->notify('message_failed', 'Mensagem ao paciente não enviada', $message->purposeLabel().': '.mb_substr($e->getMessage(), 0, 200),
                    $message->thread_id ? route('messaging.threads.show', $message->thread_id) : null, $message->branch_id, 'ia.conversas', 'warning');
            }
            if ($message->purpose === 'manual' && $final) {
                throw new BusinessRuleViolation('Mensagem não enviada: '.$e->getMessage(), 'message_failed', 502);
            }
        }
    }

    /** Cron: reenvia mensagens com nova tentativa vencida (todas as clínicas). */
    public function retryDue(): int
    {
        $due = $this->context->runAsSystem(fn () => Message::query()->withoutGlobalScopes()->where('status', 'queued')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->where('created_at', '<=', now()->subMinute())->limit(200)->get(['id', 'company_id']));

        foreach ($due as $m) {
            $this->context->runFor($m->company_id, fn () => $this->deliverById($m->id));
        }

        return $due->count();
    }

    public function thread(MessagingChannel $channel, string $phone, ?Patient $patient = null, ?string $name = null): MessageThread
    {
        $thread = MessageThread::query()->where('channel_id', $channel->id)->where('phone', $phone)->first();

        if (! $thread) {
            try {
                $thread = DB::transaction(fn () => MessageThread::create(['channel_id' => $channel->id, 'phone' => $phone, 'patient_id' => $patient?->id, 'contact_name' => $name]));
            } catch (QueryException) {
                $thread = MessageThread::query()->where('channel_id', $channel->id)->where('phone', $phone)->firstOrFail();
            }
        } elseif ($patient && ! $thread->patient_id) {
            $thread->update(['patient_id' => $patient->id]);
        }

        return $thread;
    }

    private function sendWhatsApp(Message $message): string
    {
        $channel = MessagingChannel::query()->find($message->channel_id);
        if (! $channel || ! $channel->is_active) {
            throw new MessagingException('Canal de WhatsApp desativado.', false);
        }
        $provider = $this->provider($channel);

        if ($message->template) {
            $spec = config("messaging.purposes.{$message->purpose}");
            $params = array_map(fn ($k) => (string) ($message->params[$k] ?? ''), $spec['params'] ?? []);
            $buttons = array_map(fn ($code) => $code.':'.$message->appointment_id, array_keys($spec['buttons'] ?? []));

            return $provider->sendTemplate($channel, $message->recipient, $message->template, $channel->template($message->purpose)['language'], $params, $message->appointment_id ? $buttons : []);
        }

        return $provider->sendText($channel, $message->recipient, (string) $message->body);
    }

    private function sendEmail(Message $message): string
    {
        $company = Company::query()->findOrFail($message->company_id);
        Mail::to($message->recipient)->send(new PatientMessageMail($company->trade_name, (string) config("messaging.purposes.{$message->purpose}.label", 'Aviso'), (string) $message->body));

        return 'email.'.$message->id;
    }
}
