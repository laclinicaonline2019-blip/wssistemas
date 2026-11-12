<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Company extends Model
{
    use BelongsToAccount, HasUlids;

    protected ?string $companyColumn = 'id';

    protected $fillable = ['name', 'alias', 'person_type', 'document', 'active'];

    protected function casts(): array
    {
        return ['document' => 'encrypted', 'active' => 'boolean'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(CompanySetting::class);
    }

    public function label(): string
    {
        return $this->alias ? $this->name.' ('.$this->alias.')' : $this->name;
    }
}
