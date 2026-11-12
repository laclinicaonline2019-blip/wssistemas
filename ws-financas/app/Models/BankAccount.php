<?php

namespace App\Models;

use App\Core\Support\DateOnly;
use App\Core\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankAccount extends Model
{
    use BelongsToAccount, HasUlids;

    protected $fillable = ['company_id', 'name', 'bank', 'opening_balance_cents', 'opening_date', 'active'];

    protected function casts(): array
    {
        return ['opening_date' => DateOnly::class, 'active' => 'boolean', 'opening_balance_cents' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
