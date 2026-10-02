<?php

namespace App\Modules\Messaging\Models;

use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mensagem enviada/recebida (WhatsApp ou e-mail). Funciona como caixa de saída com novas tentativas. */
class Message extends Model
{
    use BelongsToCompany, HasUlids;

    public const STATUSES = [
        'queued' => 'Na fila', 'sent' => 'Enviada', 'delivered' => 'Entregue', 'read' => 'Lida',
        'failed' => 'Falhou', 'received' => 'Recebida', 'skipped' => 'Não enviada',
    ];

    protected $fillable = [
        'branch_id', 'channel_id', 'thread_id', 'patient_id', 'appointment_id', 'channel', 'direction', 'purpose', 'recipient',
        'template', 'params', 'body', 'status', 'provider_message_id', 'error', 'next_attempt_at', 'dedupe_key', 'created_by',
    ];

    protected $attributes = ['status' => 'queued', 'attempts' => 0];

    protected function casts(): array
    {
        return [
            'params' => 'array', 'attempts' => 'integer', 'next_attempt_at' => 'datetime', 'sent_at' => 'datetime',
            'delivered_at' => 'datetime', 'read_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function purposeLabel(): string
    {
        return config("messaging.purposes.{$this->purpose}.label") ?? ['manual' => 'Mensagem da equipe', 'inbound' => 'Mensagem do paciente', 'auto_reply' => 'Resposta automática'][$this->purpose] ?? $this->purpose;
    }
}
