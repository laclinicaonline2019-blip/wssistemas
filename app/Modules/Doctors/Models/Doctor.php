<?php

namespace App\Modules\Doctors\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Branch;
use App\Modules\Scheduling\Models\DoctorService;
use App\Modules\Scheduling\Models\ScheduleTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Doctor extends Model
{
    use Auditable, BelongsToCompany, HasUlids, SoftDeletes;

    protected string $auditName = 'doctor';

    protected array $auditExclude = ['active_crm', 'active_user_key'];

    protected $fillable = ['user_id', 'name', 'social_name', 'crm', 'crm_state', 'cpf', 'email', 'phone', 'bio', 'status', 'daily_limit'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['daily_limit' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(Specialty::class, 'doctor_specialty')->withPivot('rqe');
    }

    public function services(): HasMany
    {
        return $this->hasMany(DoctorService::class)->orderBy('name');
    }

    public function scheduleTemplates(): HasMany
    {
        return $this->hasMany(ScheduleTemplate::class)->orderBy('weekday')->orderBy('start_time');
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'doctor_branch');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** Médicos que atendem em alguma das filiais informadas (null = sem restrição). */
    public function scopeInBranches(Builder $query, ?array $branchIds): Builder
    {
        return $branchIds === null ? $query : $query->whereHas('branches', fn ($q) => $q->whereIn('branches.id', $branchIds));
    }

    public function displayName(): string
    {
        return $this->social_name ?: $this->name;
    }

    /** Identificação profissional impressa em receitas/atestados: "CRM 123456/SP". */
    public function registration(): string
    {
        return "CRM {$this->crm}/{$this->crm_state}";
    }
}
