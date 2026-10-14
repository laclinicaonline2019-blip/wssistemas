<?php

namespace App\Modules\Sprint\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Payments\Models\PaymentGateway;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Configuração do Melhor Sprint da clínica (gateways preferidos e NFS-e). */
class SprintSetting extends Model
{
    use Auditable, BelongsToCompany, HasUlids;

    protected string $auditName = 'sprint_setting';

    protected $fillable = [
        'pix_gateway_id', 'card_gateway_id', 'nfse_enabled', 'nfse_auto', 'nfse_gateway_id',
        'nfse_service_code', 'nfse_service_name', 'nfse_iss_bp', 'nfse_description',
    ];

    protected $attributes = ['nfse_enabled' => false, 'nfse_auto' => false, 'nfse_iss_bp' => 0];

    protected function casts(): array
    {
        return ['nfse_enabled' => 'boolean', 'nfse_auto' => 'boolean', 'nfse_iss_bp' => 'integer'];
    }

    public static function current(): self
    {
        return self::query()->first() ?? new self;
    }

    public function nfseGateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class, 'nfse_gateway_id');
    }
}
