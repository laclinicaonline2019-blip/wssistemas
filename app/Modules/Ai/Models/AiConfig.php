<?php

namespace App\Modules\Ai\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Configuração da IA da clínica. A chave própria (opcional) fica criptografada e nunca é exibida. */
class AiConfig extends Model
{
    use Auditable, BelongsToCompany, HasUlids;

    public const PROVIDERS = [
        'claude' => 'Claude (Anthropic) — recomendado',
        'openai' => 'ChatGPT (OpenAI)',
        'mock' => 'MOCK (simulação, sem IA real)',
    ];

    public const DEFAULT_MODELS = ['claude' => 'claude-opus-5-5', 'openai' => null, 'mock' => 'mock'];

    protected string $auditName = 'ai_config';

    protected array $auditExclude = ['api_key', 'transcription_api_key'];

    protected $fillable = ['provider', 'model', 'api_key', 'assistant_name', 'instructions', 'settings', 'is_active'];

    protected $hidden = ['api_key', 'transcription_api_key'];

    protected $attributes = ['provider' => 'claude', 'assistant_name' => 'Assistente virtual', 'is_active' => false];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'transcription_api_key' => 'encrypted', 'settings' => 'array', 'is_active' => 'boolean'];
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    public function modelName(): string
    {
        return $this->model ?: (string) (self::DEFAULT_MODELS[$this->provider] ?? '');
    }

    /** Transcrição de áudio (OpenAI): chave própria para isso, a da clínica se o provedor já é OpenAI, ou a da plataforma. */
    public function transcriptionKey(): ?string
    {
        return $this->transcription_api_key ?: ($this->provider === 'openai' ? $this->api_key : null) ?: config('services.openai.key');
    }

    /** Chave da clínica ou, se vazia, a da plataforma (.env). */
    public function apiKey(): ?string
    {
        return $this->api_key ?: match ($this->provider) {
            'claude' => config('services.anthropic.key'),
            'openai' => config('services.openai.key'),
            default => null,
        };
    }
}
