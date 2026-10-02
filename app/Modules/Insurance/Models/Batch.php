<?php

namespace App\Modules\Insurance\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Organization\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Lote de faturamento (TISS loteGuias): guias de UM tipo, de UM convênio e UMA unidade.
 * Aberto → fechado (XML TISS gerado e conta a receber do convênio) → pago / parcial / cancelado.
 */
class Batch extends Model
{
    use Auditable, BelongsToCompany, HasUlids;

    public const STATUSES = ['open' => 'Aberto', 'closed' => 'Enviado / aguardando pagamento', 'partial' => 'Retorno parcial / glosa em aberto', 'paid' => 'Pago', 'cancelled' => 'Cancelado'];

    protected $table = 'insurance_batches';

    protected string $auditName = 'insurance_batch';

    /** O XML vai para a trilha só como hash (é grande e contém nº de carteirinhas). */
    protected array $auditExclude = ['xml'];

    protected $fillable = ['branch_id', 'insurer_id', 'number', 'guide_type', 'competence', 'created_by'];

    protected $attributes = ['status' => 'open', 'guides_count' => 0, 'total_cents' => 0, 'paid_cents' => 0, 'glosa_cents' => 0];

    protected function casts(): array
    {
        return [
            'total_cents' => 'integer', 'paid_cents' => 'integer', 'glosa_cents' => 'integer', 'guides_count' => 'integer',
            'xml_schema_valid' => 'boolean', 'sent_at' => 'datetime', 'closed_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Lotes não são excluídos — cancele com motivo.'));
        static::updating(function (Batch $batch) {
            if ($batch->getOriginal('xml') !== null && $batch->isDirty(['xml', 'xml_hash', 'number', 'total_cents', 'insurer_id', 'guide_type'])) {
                throw new LogicException('Lote fechado: XML e totais são imutáveis.');
            }
        });
    }

    /** Arquivo TISS como enviado à operadora (ISO-8859-1, como declara o cabeçalho XML). */
    public function xmlFile(): ?string
    {
        return $this->xml === null ? null : mb_convert_encoding($this->xml, 'ISO-8859-1', 'UTF-8');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function scopeAccessibleBranches(Builder $q, ?array $branchIds): Builder
    {
        return $branchIds === null ? $q : $q->whereIn('branch_id', $branchIds);
    }

    public function guides(): HasMany
    {
        return $this->hasMany(Guide::class)->orderBy('number');
    }

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }
}
