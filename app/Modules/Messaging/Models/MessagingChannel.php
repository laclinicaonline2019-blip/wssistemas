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

    public const PROVIDERS = [
        'meta' => 'API OFICIAL — WhatsApp Business Cloud API (Meta)',
        'zapi' => 'NÃO OFICIAL — Z-API (risco de bloqueio do número)',
        'evolution' => 'NÃO OFICIAL — Evolution API (risco de bloqueio do número)',
        'mock' => 'MOCK (simulação, nada é enviado)',
    ];

    /** Conectam um WhatsApp comum por QR Code (WhatsApp Web): fora dos termos do WhatsApp. */
    public const UNOFFICIAL = ['zapi', 'evolution'];

    public const MODES = ['mock' => 'MOCK', 'test' => 'Teste (número de teste da Meta)', 'production' => 'Produção'];

    protected string $auditName = 'messaging_channel';

    protected array $auditExclude = ['credentials', 'verify_token'];

    protected $fillable = ['provider', 'mode', 'name', 'phone_number_id', 'waba_id', 'display_phone', 'credentials', 'verify_token', 'api_version', 'templates', 'is_active', 'risk_accepted_at', 'risk_accepted_by'];

    protected $hidden = ['credentials', 'verify_token'];

    protected $attributes = ['is_active' => true, 'api_version' => 'v21.0'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'verify_token' => 'encrypted', 'templates' => 'array', 'is_active' => 'boolean', 'last_webhook_at' => 'datetime', 'risk_accepted_at' => 'datetime'];
    }

    public function credential(string $key): ?string
    {
        return $this->credentials[$key] ?? null;
    }

    public function isMock(): bool
    {
        return $this->provider === 'mock' || $this->mode === 'mock';
    }

    public function isUnofficial(): bool
    {
        return in_array($this->provider, self::UNOFFICIAL, true);
    }

    public function modeBadge(): ?string
    {
        return match (true) {
            $this->isMock() => 'MOCK',
            $this->isUnofficial() => 'NÃO OFICIAL',
            $this->mode === 'test' => 'TESTE',
            default => null,
        };
    }

    /** Modelo aprovado na Meta para a finalidade (padrão: nome sugerido no config/messaging.php). */
    public function template(string $purpose): array
    {
        $default = config("messaging.purposes.{$purpose}.template");

        return ['name' => data_get($this->templates, "{$purpose}.name") ?: $default, 'language' => data_get($this->templates, "{$purpose}.language") ?: 'pt_BR'];
    }

    public function webhookUrl(): string
    {
        // Não oficiais não assinam os eventos: o token secreto vai na URL.
        return $this->isUnofficial()
            ? route('messaging.webhook', ['channel' => $this->id, 'token' => $this->verify_token])
            : route('messaging.webhook', $this->id);
    }
}
