<?php

namespace App\Modules\Ai\Models;

use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Documents\Models\PatientFile;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Patients\Models\Patient;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Áudio, imagem ou PDF lido pela IA. Dados extraídos são "não verificados" até a conferência humana. */
class AiMedia extends Model
{
    use BelongsToCompany, HasUlids;

    protected $table = 'ai_media';

    public const KINDS = ['audio' => 'Áudio', 'image' => 'Imagem', 'document' => 'PDF'];

    public const DOC_TYPES = [
        'prescription' => 'Receita', 'exam_request' => 'Pedido de exame', 'exam_result' => 'Resultado de exame',
        'payment_receipt' => 'Comprovante de pagamento', 'medical_certificate' => 'Atestado / declaração',
        'insurance_card' => 'Carteirinha de convênio', 'identity_document' => 'Documento pessoal', 'other' => 'Outro', 'unreadable' => 'Ilegível',
    ];

    public const REVIEW = ['pending' => 'Aguardando conferência', 'verified' => 'Conferido', 'discarded' => 'Descartado'];

    protected $fillable = [
        'source', 'message_id', 'thread_id', 'patient_id', 'patient_file_id', 'kind', 'mime', 'size_bytes', 'disk', 'path', 'sha256', 'scan_status',
        'original_name', 'caption', 'status', 'transcript', 'doc_type', 'extraction', 'provider', 'model', 'error',
    ];

    protected $attributes = ['status' => 'pending', 'review_status' => 'pending', 'disk' => 'local', 'size_bytes' => 0];

    protected function casts(): array
    {
        return ['extraction' => 'array', 'reviewed_at' => 'datetime', 'size_bytes' => 'integer'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(MessageThread::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function patientFile(): BelongsTo
    {
        return $this->belongsTo(PatientFile::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    public function docTypeLabel(): ?string
    {
        return $this->doc_type ? (self::DOC_TYPES[$this->doc_type] ?? $this->doc_type) : null;
    }

    public function reviewLabel(): string
    {
        return self::REVIEW[$this->review_status] ?? $this->review_status;
    }

    /** Resumo curto, neutro, para a conversa e para a IA (sempre como "não verificado"). */
    public function summaryLine(): string
    {
        if ($this->kind === 'audio') {
            return match (true) {
                $this->transcript !== null && $this->transcript !== '' => '🎤 Áudio (transcrição automática): '.$this->transcript,
                $this->status === 'failed' => '[áudio — não foi possível transcrever]',
                default => '[áudio recebido]',
            };
        }

        $what = $this->kind === 'image' ? 'Imagem' : 'PDF';
        if ($this->status !== 'processed' || ! $this->doc_type) {
            return '['.mb_strtolower($what).' recebida'.($this->caption ? ': '.$this->caption : '').']';
        }

        $e = $this->extraction ?? [];
        $items = match ($this->doc_type) {
            'prescription' => collect($e['medications'] ?? [])->map(fn ($m) => trim(($m['name'] ?? '').' '.($m['concentration'] ?? '')))->filter()->implode('; '),
            'exam_request' => collect($e['exams'] ?? [])->pluck('name')->filter()->implode('; '),
            'payment_receipt' => trim(($e['payment']['amount'] ?? '').' '.($e['payment']['paid_at'] ?? '')),
            default => (string) ($e['summary'] ?? ''),
        };

        return "[{$what} recebida — lida automaticamente como \"{$this->docTypeLabel()}\" (NÃO VERIFICADO)".($items !== '' ? ': '.mb_substr($items, 0, 400) : '')
            .($this->caption ? ' — legenda: '.$this->caption : '').']';
    }
}
