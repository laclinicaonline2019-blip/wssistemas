<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class LocalImport extends Model
{
    use BelongsToAccount, HasUlids;

    protected $fillable = ['company_id', 'user_id', 'checksum', 'summary'];

    protected function casts(): array
    {
        return ['summary' => 'array'];
    }
}
