<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Extrato enviado (OFX/CSV/Excel/PDF): a prévia fica aqui e só vira lançamento depois da confirmação. */
class ImportBatch extends Model
{
    use BelongsToAccount, HasUlids;

    protected $fillable = ['company_id', 'user_id', 'format', 'filename', 'checksum', 'rows', 'status', 'imported', 'duplicates'];

    protected function casts(): array
    {
        return ['rows' => 'array'];
    }
}
