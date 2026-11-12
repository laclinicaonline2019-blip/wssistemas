<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

class CompanySetting extends Model
{
    use BelongsToAccount;

    protected $primaryKey = 'company_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['company_id', 'debt_budget_cents', 'debt_strategy'];

    protected function casts(): array
    {
        return ['debt_budget_cents' => 'integer'];
    }
}
