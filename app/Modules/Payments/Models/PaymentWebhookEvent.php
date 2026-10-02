<?php

namespace App\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Evento recebido de um gateway. Registrado ANTES de processar: o índice único
 * (provider, provider_event_id) torna o webhook idempotente. Sem escopo de tenant
 * automático (chega sem contexto) — consultas devem filtrar company_id.
 */
class PaymentWebhookEvent extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = ['company_id', 'gateway_id', 'provider', 'provider_event_id', 'event_type', 'provider_charge_id', 'payload', 'status', 'error', 'attempts', 'received_at', 'processed_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'received_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}
