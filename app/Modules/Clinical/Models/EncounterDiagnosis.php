<?php

namespace App\Modules\Clinical\Models;

use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Diagnóstico finalizado (cópia do CID usado — não muda se a base for atualizada). */
class EncounterDiagnosis extends Model
{
    use BelongsToCompany, HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['encounter_id', 'version', 'cid_code_id', 'code', 'description', 'cid_version', 'is_primary', 'notes'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'created_at' => 'datetime'];
    }
}
