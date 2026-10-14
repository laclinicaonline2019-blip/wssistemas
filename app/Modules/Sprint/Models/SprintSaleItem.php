<?php

namespace App\Modules\Sprint\Models;

use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Item da venda: serviço de um profissional com a regra de divisão aplicada (retrato). */
class SprintSaleItem extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = ['sale_id', 'professional_id', 'description', 'amount_cents', 'split_type', 'split_value', 'professional_cents', 'house_cents'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'split_value' => 'integer', 'professional_cents' => 'integer', 'house_cents' => 'integer'];
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(SprintProfessional::class, 'professional_id');
    }

    public function splitLabel(): string
    {
        return SplitMath::label($this->split_type, $this->split_value);
    }
}
