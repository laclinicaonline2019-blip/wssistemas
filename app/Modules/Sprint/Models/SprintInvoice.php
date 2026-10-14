<?php

namespace App\Modules\Sprint\Models;

use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** NFS-e emitida sobre a parte da clínica numa venda. */
class SprintInvoice extends Model
{
    use BelongsToCompany, HasUlids;

    public const STATUSES = [
        'pending' => 'Enviando', 'scheduled' => 'Agendada / processando', 'authorized' => 'Autorizada', 'error' => 'Erro',
        'cancelling' => 'Cancelando', 'cancelled' => 'Cancelada',
    ];

    protected $fillable = [
        'sale_id', 'provider', 'mode', 'provider_invoice_id', 'status', 'value_cents', 'sale_total_cents', 'description',
        'number', 'verification_code', 'pdf_url', 'xml_url', 'error', 'issued_at', 'last_checked_at', 'created_by',
    ];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['value_cents' => 'integer', 'sale_total_cents' => 'integer', 'issued_at' => 'datetime', 'last_checked_at' => 'datetime'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(SprintSale::class, 'sale_id');
    }

    public function isActive(): bool
    {
        return ! in_array($this->status, ['error', 'cancelled'], true);
    }

    public function modeBadge(): ?string
    {
        return $this->provider === 'mock' || $this->mode === 'mock' ? 'MOCK' : ($this->mode === 'sandbox' ? 'SANDBOX' : null);
    }
}
