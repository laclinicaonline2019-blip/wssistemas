<?php

namespace App\Modules\Sprint\Models;

use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Branch;
use App\Modules\Patients\Models\Patient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Venda do Melhor Sprint (lastreada por uma conta a receber do financeiro). */
class SprintSale extends Model
{
    use BelongsToCompany, HasUlids;

    public const STATUSES = ['open' => 'Aguardando pagamento', 'partial' => 'Pago parcialmente', 'paid' => 'Pago', 'cancelled' => 'Cancelada', 'refunded' => 'Estornada'];

    protected $fillable = [
        'branch_id', 'number', 'patient_id', 'customer_name', 'customer_document', 'description', 'receivable_id',
        'total_cents', 'professional_cents', 'house_cents', 'commission_bp', 'issue_nfse', 'created_by',
    ];

    protected $attributes = ['status' => 'open', 'issue_nfse' => false];

    protected function casts(): array
    {
        return [
            'number' => 'integer', 'total_cents' => 'integer', 'professional_cents' => 'integer', 'house_cents' => 'integer',
            'commission_bp' => 'integer', 'issue_nfse' => 'boolean', 'paid_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SprintSaleItem::class, 'sale_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SprintPayment::class, 'sale_id');
    }

    public function splits(): HasMany
    {
        return $this->hasMany(SprintSplit::class, 'sale_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SprintInvoice::class, 'sale_id');
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function scopeAccessibleBranches(Builder $q, ?array $branchIds): Builder
    {
        return $branchIds === null ? $q : $q->whereIn('branch_id', $branchIds);
    }

    public function code(): string
    {
        return 'MS-'.str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    public function customerLabel(): string
    {
        return $this->patient?->displayName() ?? $this->customer_name ?? 'Consumidor';
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'partial'], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
