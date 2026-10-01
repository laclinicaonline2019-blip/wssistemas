<?php

namespace App\Modules\Clinical\Models;

use App\Core\Audit\Auditable;
use App\Core\Support\Format;
use App\Core\Tenancy\Exceptions\CrossTenantViolation;
use App\Core\Tenancy\Exceptions\TenantContextMissing;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Medicamento. company_id NULL = base global da plataforma (somente leitura para
 * as clínicas); company_id preenchido = cadastro próprio da clínica.
 */
class Medication extends Model
{
    use Auditable, HasUlids;

    public const CONTROL_TYPES = [
        'none' => 'Venda livre / receita simples',
        'antimicrobial' => 'Antimicrobiano (retenção de receita)',
        'A1' => 'A1 — entorpecentes', 'A2' => 'A2 — entorpecentes', 'A3' => 'A3 — psicotrópicos',
        'B1' => 'B1 — psicotrópicos', 'B2' => 'B2 — psicotrópicos anorexígenos',
        'C1' => 'C1 — controle especial', 'C2' => 'C2 — retinoides', 'C3' => 'C3 — imunossupressores',
        'C4' => 'C4 — antirretrovirais', 'C5' => 'C5 — anabolizantes',
    ];

    protected string $auditName = 'medication';

    protected array $auditExclude = ['search_text'];

    protected $fillable = ['active_ingredient', 'commercial_name', 'presentation', 'concentration', 'manufacturer', 'route', 'default_posology', 'control_type', 'notes', 'is_active', 'is_sample', 'created_by'];

    protected $attributes = ['control_type' => 'none', 'is_active' => true, 'is_sample' => false];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_sample' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Clínicas veem a base global + os próprios cadastros (nunca os de outra clínica).
        static::addGlobalScope('tenant_or_global', function (Builder $query) {
            $context = app(TenantContext::class);

            if ($context->isSystem()) {
                return;
            }

            if (! $context->hasCompany()) {
                throw new TenantContextMissing;
            }

            $query->where(fn ($q) => $q->whereNull('medications.company_id')->orWhere('medications.company_id', $context->companyId()));
        });

        static::creating(function (Medication $m) {
            $context = app(TenantContext::class);

            if (! $context->isSystem()) {
                if ($m->company_id && $m->company_id !== $context->companyId()) {
                    throw new CrossTenantViolation;
                }
                $m->company_id = $context->companyId();
            }
        });

        static::updating(function (Medication $m) {
            if ($m->isDirty('company_id')) {
                throw new CrossTenantViolation('company_id é imutável.');
            }
        });

        static::saving(function (Medication $m) {
            $m->search_text = Format::searchable(implode(' ', array_filter([$m->active_ingredient, $m->commercial_name, $m->concentration, $m->presentation])));
        });
    }

    public function isGlobal(): bool
    {
        return $this->company_id === null;
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        foreach (array_filter(explode(' ', Format::searchable($term)), fn ($w) => mb_strlen($w) >= 2) as $word) {
            $query->where('search_text', 'like', '%'.addcslashes($word, '%_\\').'%');
        }

        return $query->where('is_active', true);
    }

    public function label(): string
    {
        return trim(implode(' ', array_filter([
            $this->active_ingredient,
            $this->concentration,
            $this->presentation ? "({$this->presentation})" : null,
            $this->commercial_name ? "— {$this->commercial_name}" : null,
        ])));
    }

    public function isControlled(): bool
    {
        return $this->control_type !== 'none';
    }
}
