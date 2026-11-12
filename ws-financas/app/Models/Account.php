<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Cliente da plataforma (pessoa física ou jurídica) — fronteira de isolamento dos dados. */
class Account extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'person_type', 'document', 'email', 'phone', 'status', 'status_reason', 'canceled_at', 'purge_after'];

    protected function casts(): array
    {
        return ['document' => 'encrypted', 'phone' => 'encrypted', 'canceled_at' => 'datetime', 'purge_after' => 'datetime'];
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function plan(): ?Plan
    {
        return $this->subscription?->plan;
    }

    public function feature(string $feature): bool
    {
        return (bool) $this->plan()?->has($feature);
    }

    /** Pode registrar novos lançamentos? (suspenso/cancelado = só leitura; dados preservados) */
    public function canWrite(): bool
    {
        $s = $this->subscription;

        return $this->status === 'active' && $s !== null && in_array($s->status, ['trialing', 'active', 'past_due'], true);
    }
}
