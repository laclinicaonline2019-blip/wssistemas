<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Meta de gasto de uma empresa: mensal ou semanal; category '' = total de despesas. */
class Budget extends Model
{
    use BelongsToAccount, HasUlids;

    public const PERIODS = ['mes' => 'Mensal', 'semana' => 'Semanal'];

    protected $fillable = ['company_id', 'period', 'category', 'amount_cents'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer'];
    }
}
