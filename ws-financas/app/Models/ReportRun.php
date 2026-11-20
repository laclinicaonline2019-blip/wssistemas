<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Registro de envio de um relatório automático (único por empresa + tipo + período + canal = não envia duas vezes). */
class ReportRun extends Model
{
    use BelongsToAccount, HasUlids;

    protected $fillable = ['company_id', 'kind', 'period_key', 'channel', 'status', 'detail'];
}
