<?php

namespace App\Modules\Messaging\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Número de WhatsApp da clínica. Credenciais e token do webhook criptografados (APP_KEY). */
class MessagingChannel extends Model
{
    use Auditable, BelongsToCompany, HasUlids;

    public const PROVIDERS = ['meta' => 'WhatsApp Business — Cloud API oficial (Meta)', 'mock' => 'MOCK (simulação, nada é enviado)'];

    public const MODES = ['mock' => 'MOCK', 'test' => 'Teste (número de teste da Meta)', 'production' => 'Produção'];

    protected string $auditName = 'messaging_channel';

    protected array $auditExclude = ['credentials', 'verify_token'];

    protected $fillable = ['provider', 'mode', 'name', 'phone_number_id', 'waba_id', 'display_phone', 'credentials', 'verify_token', 'api_version', 'templates', 'is_active'];

    protected $hidden = ['credentials', 'verify_token'];

    protected $attributes = ['is_active' => true, 'api_version' => 'v21.0'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'verify_token' => 'encrypted', 'templates' => 'array', 'is_active' => 'boolean', 'last_webhook_at' => 'datetime'];
    }

    public function credential(string $key): ?string
    {
        return $this->credentials[$key] ?? null;
    }

    public function isMock(): bool
    {
        return $this->provider === 'mock' || $this->mode === 'mock';
    }

    public function modeBadge(): ?string
    {
        return $this->isMock() ? 'MOCK' : ($this->mode === 'test' ? 'TESTE' : null);
    }

    /** Modelo aprovado na Meta para a finalidade (padrão: nome sugerido no config/messaging.php). */
    public function template(string $purpose): array
    {
        $default = config("messaging.purposes.{$purpose}.template");

        return ['name' => data_get($this->templates, "{$purpose}.name") ?: $default, 'language' => data_get($this->templates, "{$purpose}.language") ?: 'pt_BR'];
    }

    public function webhookUrl(): string
    {
        return route('messaging.webhook', $this->id);
    }
}
