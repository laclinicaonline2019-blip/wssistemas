<?php

namespace App\Modules\Ai\Models;

use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Messaging\Models\MessageThread;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Estado da IA numa conversa: ativa ou com a equipe (handoff), paciente identificado, rascunho de agendamento. */
class AiSession extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = ['thread_id', 'patient_id', 'status', 'handoff_reason', 'state'];

    protected $attributes = ['status' => 'active', 'replies' => 0, 'input_tokens' => 0, 'output_tokens' => 0];

    protected function casts(): array
    {
        return ['state' => 'array', 'last_reply_at' => 'datetime'];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(MessageThread::class);
    }

    public function stateValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->state, $key, $default);
    }

    public function putState(string $key, mixed $value): void
    {
        $state = $this->state ?? [];
        data_set($state, $key, $value);
        $this->state = $state;
    }
}
