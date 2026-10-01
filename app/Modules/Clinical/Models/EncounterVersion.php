<?php

namespace App\Modules\Clinical\Models;

use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Versão imutável do registro clínico (original ou adendo), encadeada por hash. */
class EncounterVersion extends Model
{
    use BelongsToCompany, HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['encounter_id', 'version', 'kind', 'data', 'diagnoses', 'reason', 'author_id', 'author_doctor_id', 'prev_hash', 'hash', 'created_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'diagnoses' => 'array', 'version' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Registro clínico finalizado é imutável — use um adendo.'));
        static::deleting(fn () => throw new LogicException('Registro clínico não pode ser excluído.'));
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id')->withTrashed();
    }

    public function authorDoctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'author_doctor_id')->withTrashed();
    }
}
