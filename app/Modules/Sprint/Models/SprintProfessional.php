<?php

namespace App\Modules\Sprint\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Doctors\Models\Doctor;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Profissional que recebe parte das vendas do Melhor Sprint (médico ou parceiro). */
class SprintProfessional extends Model
{
    use Auditable, BelongsToCompany, HasUlids;

    public const KINDS = ['doctor' => 'Médico', 'partner' => 'Parceiro'];

    protected string $auditName = 'sprint_professional';

    protected $fillable = [
        'doctor_id', 'kind', 'name', 'document', 'email', 'phone', 'pix_key', 'bank_info',
        'asaas_wallet_id', 'cielo_subordinate_id', 'split_type', 'split_value', 'notes', 'is_active',
    ];

    protected $attributes = ['kind' => 'partner', 'split_type' => 'percent', 'split_value' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return ['split_value' => 'integer', 'is_active' => 'boolean'];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /** Parte do profissional sobre um valor (arredondamento comercial, nunca acima do valor). */
    public function shareOf(int $amount): int
    {
        return SplitMath::share($amount, $this->split_type, $this->split_value);
    }

    public function splitLabel(): string
    {
        return SplitMath::label($this->split_type, $this->split_value);
    }
}
