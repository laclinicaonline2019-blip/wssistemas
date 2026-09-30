<?php

namespace App\Modules\Patients\Models;

use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PatientInsurance extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = ['patient_id', 'insurer_name', 'plan_name', 'card_number', 'valid_until', 'is_primary'];

    protected function casts(): array
    {
        return ['valid_until' => 'date', 'is_primary' => 'boolean'];
    }

    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->endOfDay()->isPast();
    }
}
