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

    public function costCenters(): HasMany
    {
        return $this->hasMany(CostCenter::class);
    }

    public function customCategories(): HasMany
    {
        return $this->hasMany(CompanyCategory::class);
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    public function isPerson(): bool
    {
        return $this->person_type === 'pf';
    }

    /** Categorias da entidade: padrão de PF ou de PJ + as criadas pelo cliente. @return list<string> */
    public function categories(string $type): array
    {
        $base = (array) config('wsf.categories_by_person.'.($this->isPerson() ? 'pf' : 'pj').'.'.$type, config('wsf.categories.'.$type));
        $own = CompanyCategory::query()->where('company_id', $this->id)->where('type', $type)->where('active', true)->orderBy('name')->pluck('name')->all();
        $list = array_values(array_unique(array_merge(array_diff($base, ['Outros', 'Outras entradas']), $own)));
        $list[] = $type === 'receita' ? 'Outras entradas' : 'Outros';

        return $list;
    }

    public function label(): string
    {
        return $this->alias ? $this->name.' ('.$this->alias.')' : $this->name;
    }
}
