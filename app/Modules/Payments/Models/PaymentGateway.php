<?php

namespace App\Modules\Payments\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Gateway de pagamento configurado pela clínica. Credenciais e token criptografados (APP_KEY). */
class PaymentGateway extends Model
{
    use Auditable, BelongsToCompany, HasUlids;

    public const PROVIDERS = ['asaas' => 'ASAAS', 'cielo' => 'Cielo (Link de Pagamento)', 'cielo_api' => 'Cielo — API E-commerce com split (cartão)', 'mock' => 'MOCK (simulação, sem dinheiro real)'];

    public const MODES = ['mock' => 'MOCK', 'sandbox' => 'SANDBOX (homologação)', 'production' => 'Produção'];

    protected string $auditName = 'payment_gateway';

    protected array $auditExclude = ['credentials', 'webhook_token'];

    protected $fillable = ['provider', 'mode', 'name', 'credentials', 'webhook_token', 'settings', 'is_active', 'is_default'];

    protected $hidden = ['credentials', 'webhook_token'];

    protected $attributes = ['is_active' => true, 'is_default' => false];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'webhook_token' => 'encrypted', 'settings' => 'array', 'is_active' => 'boolean', 'is_default' => 'boolean'];
    }

    public function credential(string $key): ?string
    {
        return $this->credentials[$key] ?? null;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    public function isMock(): bool
    {
        return $this->provider === 'mock' || $this->mode === 'mock';
    }

    public function modeBadge(): ?string
    {
        return $this->isMock() ? 'MOCK' : ($this->mode === 'sandbox' ? 'SANDBOX' : null);
    }

    public function webhookUrl(): string
    {
        return route('payments.webhook', $this->id);
    }
}
