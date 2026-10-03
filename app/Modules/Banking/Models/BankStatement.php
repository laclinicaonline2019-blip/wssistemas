<?php

namespace App\Modules\Banking\Models;

use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma importação de extrato (arquivo OFX/CSV ou sincronização Open Finance). */
class BankStatement extends Model
{
    use BelongsToCompany, HasUlids;

    public const UPDATED_AT = null;

    public const SOURCES = ['ofx' => 'OFX', 'csv' => 'CSV', 'openfinance' => 'Open Finance'];

    protected $fillable = ['bank_account_id', 'source', 'filename', 'sha256', 'period_start', 'period_end', 'balance_cents', 'balance_date', 'lines_total', 'lines_new', 'imported_by'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'balance_date' => 'date'];
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
