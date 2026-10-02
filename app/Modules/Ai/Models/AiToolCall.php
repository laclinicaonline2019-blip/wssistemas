<?php

namespace App\Modules\Ai\Models;

use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Ação executada pela IA (consultar horários, agendar, cancelar…), com entrada e resultado. */
class AiToolCall extends Model
{
    use BelongsToCompany, HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['session_id', 'tool', 'input', 'result', 'is_error'];

    protected function casts(): array
    {
        return ['input' => 'array', 'result' => 'array', 'is_error' => 'boolean'];
    }
}
