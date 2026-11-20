<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Relatórios automáticos do cliente: semanal (segunda) e/ou mensal (dia 1º), por WhatsApp e/ou e-mail, por entidade. */
class ReportPreference extends Model
{
    use BelongsToAccount, HasUlids;

    protected ?string $companyColumn = null;

    protected $fillable = ['weekly', 'monthly', 'via_whatsapp', 'via_email', 'company_ids', 'email', 'whatsapp_contact_id'];

    protected function casts(): array
    {
        return ['weekly' => 'boolean', 'monthly' => 'boolean', 'via_whatsapp' => 'boolean', 'via_email' => 'boolean', 'company_ids' => 'array'];
    }

    public function enabled(): bool
    {
        return ($this->weekly || $this->monthly) && ($this->via_whatsapp || $this->via_email);
    }
}
