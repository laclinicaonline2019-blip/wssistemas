<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasUlids;

    public const FEATURES = [
        'whatsapp' => 'Assistente no WhatsApp',
        'consolidated' => 'Visão consolidada das empresas',
        'advanced_reports' => 'Relatórios avançados e exportação',
        'advanced_permissions' => 'Permissões avançadas (gestor, financeiro, consulta)',
        'accountant' => 'Acesso do contador',
        'ai' => 'Interpretação por inteligência artificial',
    ];

    protected $fillable = ['code', 'name', 'description', 'price_monthly_cents', 'price_yearly_cents', 'max_companies', 'max_users',
        'whatsapp_messages_month', 'ai_calls_month', 'features', 'active', 'sort'];

    protected function casts(): array
    {
        return ['features' => 'array', 'active' => 'boolean'];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function has(string $feature): bool
    {
        return (bool) ($this->features[$feature] ?? false);
    }

    public function priceFor(string $cycle): int
    {
        return $cycle === 'yearly' ? (int) $this->price_yearly_cents : (int) $this->price_monthly_cents;
    }
}
