<?php

namespace App\Modules\Ai\Services\Media;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Models\AiConfig;
use App\Modules\Ai\Models\AiMedia;
use App\Modules\Ai\Services\AiReceptionist;
use App\Modules\Documents\Models\PatientFile;
use App\Modules\Documents\Services\PatientFileService;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Models\MessagingChannel;
use App\Modules\Messaging\Services\MessageService;
use App\Modules\Messaging\Services\NotificationCenter;
use App\Modules\Patients\Models\Patient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Mídia recebida pelo WhatsApp (Fase 13):
 * baixa → guarda em disco privado → áudio: transcreve / imagem e PDF: lê (OCR + campos) →
 * o texto da mensagem passa a ser o resumo "NÃO VERIFICADO" → equipe avisada para conferir →
 * a recepcionista virtual responde (se estiver atendendo a conversa).
 */
class MediaPipeline
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly MessageService $messages,
        private readonly MediaStore $store,
        private readonly Transcriber $transcriber,
        private readonly DocumentReader $reader,
        private readonly NotificationCenter $notifications,
        private readonly AiReceptionist $receptionist,
        private readonly PatientFileService $files,
        private readonly AuditLogger $audit,
    ) {}

    public function processInbound(string $companyId, string $messageId, array $descriptor): void
    {
        $this->context->runFor($companyId, function () use ($companyId, $messageId, $descriptor) {
            $message = Message::query()->find($messageId);
            if (! $message || AiMedia::query()->where('message_id', $messageId)->exists()) {
                return; // evento repetido
            }
            $thread = MessageThread::query()->with('patient')->find($message->thread_id);
            $channel = MessagingChannel::query()->find($message->channel_id);
            $media = AiMedia::create([
                'source' => 'whatsapp', 'message_id' => $message->id, 'thread_id' => $message->thread_id, 'patient_id' => $thread?->patient_id,
                'kind' => $descriptor['kind'] ?? 'document', 'mime' => $descriptor['mime'] ?? null, 'caption' => isset($descriptor['caption']) ? mb_substr((string) $descriptor['caption'], 0, 1000) : null,
                'original_name' => isset($descriptor['filename']) ? mb_substr((string) $descriptor['filename'], 0, 191) : null,
            ]);

            $bytes = null;
            try {
                [$bytes] = $this->messages->provider($channel)->downloadMedia($channel, $descriptor);
                $this->saveFile($media, $bytes, $companyId);
            } catch (Throwable $e) {
                $media->forceFill(['status' => 'failed', 'error' => mb_substr('Download: '.$e->getMessage(), 0, 500)])->save();
                $bytes = null;
            }

            if ($bytes !== null) {
                $this->analyse($media, $bytes, $this->receptionist->sessionIdFor($thread));
            }

            $message->forceFill(['body' => mb_substr($media->summaryLine(), 0, 4096)])->save();
            $this->notifyStaff($media, $thread);

            if ($thread && $this->receptionist->accepts($thread)) {
                $this->receptionist->respond($companyId, $thread->id, $message->id);
            } elseif ($thread) {
                $this->notifications->notify('whatsapp_message', 'Nova mensagem no WhatsApp',
                    ($thread->patient?->displayName() ?? $thread->contact_name ?? '+'.$thread->phone).': '.mb_substr($media->summaryLine(), 0, 160),
                    route('messaging.threads.show', $thread), null, 'ia.conversas');
            }
        });
    }

    /** Equipe pede a leitura de um arquivo já anexado ao paciente (Fase 6). */
    public function readPatientFile(User $actor, PatientFile $file): AiMedia
    {
        $config = AiConfig::query()->first();
        if (! $config?->is_active) {
            throw new BusinessRuleViolation('Ative o atendimento por IA (Administração → Atendimento IA) para ler documentos.', 'ai_inactive');
        }
        $bytes = (string) Storage::disk($file->disk)->get($file->path);
        $media = AiMedia::create([
            'source' => 'upload', 'patient_id' => $file->patient_id, 'patient_file_id' => $file->id, 'kind' => $file->mime === 'application/pdf' ? 'document' : 'image',
            'mime' => $file->mime, 'size_bytes' => $file->size_bytes, 'disk' => $file->disk, 'path' => $file->path, 'sha256' => $file->sha256, 'original_name' => $file->original_name,
        ]);
        $this->extract($config, $media, $bytes);
        $this->audit->record('ai.document_read', $media, metadata: ['patient_file_id' => $file->id, 'status' => $media->status]);

        return $media;
    }

    public function verify(User $actor, AiMedia $media, ?string $notes): void
    {
        $media->forceFill(['review_status' => 'verified', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_notes' => $notes ? mb_substr($notes, 0, 1000) : null])->save();
        $this->audit->record('ai.media_verified', $media, metadata: ['doc_type' => $media->doc_type]);
    }

    public function discard(User $actor, AiMedia $media, string $reason): void
    {
        // Nada é apagado: só sai da fila de conferência.
        $media->forceFill(['review_status' => 'discarded', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_notes' => mb_substr($reason, 0, 1000)])->save();
        $this->audit->record('ai.media_discarded', $media, metadata: ['reason' => mb_substr($reason, 0, 200)]);
    }

    /** Anexa a imagem/PDF recebida à ficha do paciente (arquivo privado, não liberado no portal). */
    public function attach(User $actor, AiMedia $media, Patient $patient, string $category, string $title): PatientFile
    {
        if ($media->patient_file_id) {
            throw new BusinessRuleViolation('Este arquivo já está na ficha do paciente.', 'media_already_attached');
        }
        if ($media->kind === 'audio' || ! $media->path || ! isset(PatientFile::MIMES[$media->mime])) {
            throw new BusinessRuleViolation('Só imagens e PDFs podem ser anexados à ficha.', 'media_not_attachable');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'aim');
        file_put_contents($tmp, $this->store->get($media->path));
        try {
            $upload = new UploadedFile($tmp, ($media->original_name ?: 'whatsapp-'.$media->id).'.'.PatientFile::MIMES[$media->mime], $media->mime, null, true);
            $file = $this->files->store($actor, $patient, $upload, ['category' => $category, 'title' => $title]);
        } finally {
            @unlink($tmp);
        }
        $media->forceFill(['patient_file_id' => $file->id, 'patient_id' => $patient->id])->save();
        $this->audit->record('ai.media_attached', $media, metadata: ['patient_file_id' => $file->id, 'patient_id' => $patient->id]);

        return $file;
    }

    private function saveFile(AiMedia $media, string $bytes, string $companyId): void
    {
        $f = $this->store->put($companyId, $bytes);
        $media->forceFill(['kind' => $f['kind'], 'mime' => $f['mime'], 'size_bytes' => $f['size'], 'path' => $f['path'], 'sha256' => $f['sha256']])->save();
    }

    private function analyse(AiMedia $media, string $bytes, ?string $sessionId): void
    {
        $config = AiConfig::query()->first();
        if (! $config?->is_active) {
            $media->forceFill(['status' => 'skipped', 'error' => 'IA desligada na clínica.'])->save();

            return;
        }

        if ($media->kind === 'audio') {
            if (! $config->setting('audio_enabled', true)) {
                $media->forceFill(['status' => 'skipped', 'error' => 'Transcrição de áudio desligada.'])->save();

                return;
            }
            try {
                $text = $this->transcriber->transcribe($config, $bytes, pathinfo((string) $media->path, PATHINFO_EXTENSION) ?: 'ogg');
                $media->forceFill(['status' => 'processed', 'transcript' => mb_substr($text, 0, 10000), 'provider' => $config->provider === 'mock' ? 'mock' : 'openai',
                    'model' => $config->provider === 'mock' ? 'mock' : (string) $config->setting('transcription_model', 'whisper-1')])->save();
            } catch (Throwable $e) {
                report($e);
                $media->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
            }

            return;
        }

        if (! $config->setting('media_enabled', true)) {
            $media->forceFill(['status' => 'skipped', 'error' => 'Leitura de documentos desligada.'])->save();

            return;
        }
        $this->extract($config, $media, $bytes, $sessionId);
    }

    private function extract(AiConfig $config, AiMedia $media, string $bytes, ?string $sessionId = null): void
    {
        try {
            $data = $this->reader->read($config, $media, $bytes, $sessionId);
            $media->forceFill(['status' => 'processed', 'doc_type' => $data['doc_type'], 'extraction' => $data, 'provider' => $config->provider, 'model' => $config->modelName(), 'error' => null])->save();
        } catch (Throwable $e) {
            report($e);
            $media->forceFill(['status' => 'failed', 'provider' => $config->provider, 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
        }
    }

    private function notifyStaff(AiMedia $media, ?MessageThread $thread): void
    {
        if ($media->kind === 'audio') {
            return; // áudio vira texto na conversa
        }
        $who = $thread?->patient?->displayName() ?? $thread?->contact_name ?? ($thread ? '+'.$thread->phone : 'Paciente');
        $receipt = $media->doc_type === 'payment_receipt';
        $this->notifications->notify('ai_media', $receipt ? 'Comprovante de pagamento recebido — conferir' : 'Documento recebido pelo WhatsApp — conferir',
            $who.' · '.($media->docTypeLabel() ?? AiMedia::KINDS[$media->kind]).($media->status === 'failed' ? ' (não foi possível ler)' : ' (leitura automática, não verificada)'),
            route('ai.media.show', $media), null, 'ia.conversas', $receipt ? 'warning' : 'info');
    }
}
