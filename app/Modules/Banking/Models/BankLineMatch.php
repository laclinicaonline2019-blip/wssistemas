<?php

namespace App\Modules\Banking\Models;

use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Finance\Models\FinancialTransaction;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Vínculo linha do extrato × lançamento do livro. Desfazer marca undone_at (nada é apagado). */
class BankLineMatch extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = ['line_id', 'transaction_id', 'origin', 'matched_by'];

    protected $attributes = ['origin' => 'manual'];

    protected function casts(): array
    {
        return ['undone_at' => 'datetime'];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'transaction_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class, 'line_id');
    }
}
