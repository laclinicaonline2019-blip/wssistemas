<?php

namespace App\Modules\Payments\Models;

use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Cliente do paciente no gateway (ex.: customer do ASAAS). */
class PaymentCustomer extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = ['gateway_id', 'patient_id', 'provider_customer_id'];
}
