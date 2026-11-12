<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Debt extends Model
{
    use BelongsToAccount, HasUlids, SoftDeletes;

    protected $fillable = ['company_id', 'creditor', 'balance_cents', 'monthly_rate', 'min_payment_cents', 'is_sample', 'external_ref'];

    protected function casts(): array
    {
        return ['monthly_rate' => 'float', 'is_sample' => 'boolean', 'balance_cents' => 'integer', 'min_payment_cents' => 'integer'];
    }
}
