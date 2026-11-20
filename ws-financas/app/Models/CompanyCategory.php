<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Categoria criada pelo cliente para uma empresa (além das padrão de PF/PJ). */
class CompanyCategory extends Model
{
    use BelongsToAccount, HasUlids;

    protected $fillable = ['company_id', 'type', 'name', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
