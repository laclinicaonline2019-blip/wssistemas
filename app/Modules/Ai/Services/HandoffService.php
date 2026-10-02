<?php

namespace App\Modules\Ai\Services;

use App\Core\Audit\AuditLogger;
use App\Modules\Ai\Models\AiSession;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Services\NotificationCenter;

/**
 * Passagem da conversa entre a IA e a equipe (handoff).
 * Com a sessão em "handoff" a IA não responde mais nesta conversa até alguém devolvê-la.
 */
class HandoffService
{
    public function __construct(
        private readonly NotificationCenter $notifications,
        private readonly AuditLogger $audit,
    ) {}

    public function session(MessageThread $thread): AiSession
    {
        return AiSession::query()->firstOrCreate(['thread_id' => $thread->id], ['patient_id' => $thread->patient_id]);
    }

    /** A IA (ou uma regra de segurança) passa a conversa para a equipe. */
    public function handoff(AiSession $session, MessageThread $thread, string $reason, string $level = 'warning'): array
    {
        if ($session->status !== 'handoff') {
            $session->forceFill(['status' => 'handoff', 'handoff_reason' => mb_substr($reason, 0, 255)])->save();
            $this->notifications->notify('ai_handoff', 'Atendimento IA: conversa para a equipe',
                ($thread->patient?->displayName() ?? $thread->contact_name ?? '+'.$thread->phone).' — '.$reason,
                route('messaging.threads.show', $thread), null, 'ia.conversas', $level);
            $this->audit->record('ai.handoff', $session, metadata: ['thread_id' => $thread->id, 'reason' => mb_substr($reason, 0, 255)], actorType: 'system');
        }

        return ['handed_off' => true, 'next' => 'Avise o paciente, em uma frase, que a equipe da clínica vai continuar o atendimento por aqui.'];
    }

    /** Alguém da equipe assume a conversa (pausa a IA). */
    public function takeOver(User $user, MessageThread $thread): AiSession
    {
        $session = $this->session($thread);
        if ($session->status !== 'handoff') {
            $session->forceFill(['status' => 'handoff', 'handoff_reason' => 'Assumida por '.$user->name])->save();
            $this->audit->record('ai.taken_over', $session, metadata: ['thread_id' => $thread->id]);
        }

        return $session;
    }

    /** Devolve a conversa à IA (o rascunho de agendamento é descartado). */
    public function release(User $user, MessageThread $thread): AiSession
    {
        $session = $this->session($thread);
        $session->putState('draft', null);
        $session->putState('id_attempts', 0);
        $session->forceFill(['status' => 'active', 'handoff_reason' => null])->save();
        $this->audit->record('ai.released', $session, metadata: ['thread_id' => $thread->id]);

        return $session;
    }
}
