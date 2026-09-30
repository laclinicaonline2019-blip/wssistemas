<?php

namespace App\Modules\Patients\Models;

use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Registro de concessão/revogação de consentimento (imutável: o histórico é a prova). */
class PatientConsent extends Model
{
    use BelongsToCompany, HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['patient_id', 'purpose', 'term_version', 'granted', 'channel', 'notes', 'recorded_by', 'ip_address'];

    protected function casts(): array
    {
        return ['granted' => 'boolean', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Consentimentos são imutáveis: registre uma nova concessão/revogação.'));
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }

    public function purposeLabel(): string
    {
        return config("consents.purposes.{$this->purpose}.label", $this->purpose);
    }
}
