<?php

namespace App\Modules\Ai\Models;

use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Uma chamada ao modelo de IA (tokens, latência, motivo de parada) — base para custos e limites do plano. */
class AiRequest extends Model
{
    use BelongsToCompany, HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['session_id', 'provider', 'model', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'stop_reason', 'latency_ms', 'error'];
}
