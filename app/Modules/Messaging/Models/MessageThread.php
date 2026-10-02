<?php

namespace App\Modules\Messaging\Models;

use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Patients\Models\Patient;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Conversa de WhatsApp com um telefone. */
class MessageThread extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = ['channel_id', 'phone', 'contact_name', 'patient_id', 'last_inbound_at', 'last_message_at', 'unread_count', 'status'];

    protected $attributes = ['unread_count' => 0, 'status' => 'open'];

    protected function casts(): array
    {
        return ['last_inbound_at' => 'datetime', 'last_message_at' => 'datetime', 'unread_count' => 'integer'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(MessagingChannel::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'thread_id');
    }

    /** WhatsApp: texto livre só até 24 h após a última mensagem do paciente; depois, só modelo aprovado. */
    public function windowOpen(): bool
    {
        return $this->last_inbound_at !== null && $this->last_inbound_at->gt(now()->subHours(24));
    }
}
