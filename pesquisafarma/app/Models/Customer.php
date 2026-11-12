<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $guarded = [];

    protected $hidden = ['portal_code'];

    protected $casts = [
        'lat' => 'float', 'lng' => 'float', 'loyalty_enabled' => 'boolean', 'accepts_marketing' => 'boolean',
        'birth_date' => 'date', 'lgpd_consent_at' => 'datetime', 'portal_code_expires_at' => 'datetime',
        'last_contact_at' => 'datetime', 'bot_data' => 'array',
    ];

    public function neighborhood(): BelongsTo
    {
        return $this->belongsTo(Neighborhood::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function points(): HasMany
    {
        return $this->hasMany(PointsEntry::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(Redemption::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WaMessage::class);
    }

    /** Etapas do cadastro feito pelo robô do WhatsApp. */
    /** Etapas do pós-venda (respostas esperadas pelo robô). */
    public const FOLLOWUP_STATES = ['pos_venda', 'aguardando_nota'];

    public const REGISTRATION_STATES = ['novo', 'nome', 'cep', 'numero', 'endereco', 'lgpd', 'promo'];

    public function isRegistered(): bool
    {
        return ! in_array($this->bot_state, self::REGISTRATION_STATES, true) && filled($this->name);
    }

    public function displayName(): string
    {
        return $this->name ?: $this->phone;
    }

    public function fullAddress(): string
    {
        return trim(implode(', ', array_filter([
            trim(($this->address ?? '').' '.($this->number ?? '')), $this->complement, $this->neighborhood?->name, $this->city,
        ])));
    }

    /** Coordenadas do cliente: as próprias ou o centro do bairro. */
    public function coordinates(): ?array
    {
        if ($this->lat && $this->lng) {
            return [$this->lat, $this->lng];
        }
        $n = $this->neighborhood;

        return ($n && $n->lat && $n->lng) ? [$n->lat, $n->lng] : null;
    }
}
