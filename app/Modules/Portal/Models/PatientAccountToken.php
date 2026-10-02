<?php

namespace App\Modules\Portal\Models;

use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Link de ativação/redefinição de senha (só o hash SHA-256 é gravado; uso único; expira). */
class PatientAccountToken extends Model
{
    use BelongsToCompany, HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['account_id', 'purpose', 'token_hash', 'expires_at', 'created_by'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PatientAccount::class, 'account_id');
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
