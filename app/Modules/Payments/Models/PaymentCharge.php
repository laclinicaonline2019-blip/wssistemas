<?php

namespace App\Modules\Payments\Models;

use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Finance\Models\FinancialTransaction;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Cobrança online (PIX, boleto, cartão) vinculada a uma conta a receber. */
class PaymentCharge extends Model
{
    use BelongsToCompany, HasUlids;

    public const BILLING_TYPES = ['pix' => 'PIX', 'boleto' => 'Boleto', 'credit_card' => 'Cartão de crédito', 'undefined' => 'Paciente escolhe'];

    public const STATUSES = [
        'pending' => 'Aguardando pagamento', 'paid' => 'Pago', 'overdue' => 'Vencida', 'cancelled' => 'Cancelada',
        'refunded' => 'Estornada', 'failed' => 'Falhou', 'review' => 'Revisar (divergência)',
    ];

    protected $fillable = [
        'branch_id', 'receivable_id', 'gateway_id', 'provider', 'mode', 'patient_id', 'amount_cents', 'billing_type', 'status',
        'provider_charge_id', 'payment_url', 'pix_payload', 'pix_qr_image', 'due_date', 'idempotency_key', 'public_token',
        'split_snapshot', 'created_by',
    ];

    protected $hidden = ['pix_qr_image'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer', 'paid_cents' => 'integer', 'net_cents' => 'integer', 'due_date' => 'date',
            'paid_at' => 'datetime', 'last_checked_at' => 'datetime', 'cancelled_at' => 'datetime', 'split_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Cobranças não são excluídas — cancele.'));
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'overdue'], true);
    }

    public function modeBadge(): ?string
    {
        return $this->provider === 'mock' || $this->mode === 'mock' ? 'MOCK' : ($this->mode === 'sandbox' ? 'SANDBOX' : null);
    }

    public function publicUrl(): string
    {
        return route('payments.public', $this->public_token);
    }
}
