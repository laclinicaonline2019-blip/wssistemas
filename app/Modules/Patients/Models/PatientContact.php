<?php

namespace App\Modules\Patients\Models;

use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Responsável legal, contato de emergência ou outro contato do paciente. */
class PatientContact extends Model
{
    use BelongsToCompany, HasUlids;

    public const TYPES = ['guardian' => 'Responsável legal', 'emergency' => 'Contato de emergência', 'other' => 'Outro'];

    protected $fillable = ['patient_id', 'type', 'name', 'relationship', 'cpf', 'phone', 'email'];
}
