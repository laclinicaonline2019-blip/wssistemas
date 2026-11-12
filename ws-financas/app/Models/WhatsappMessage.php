<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Registro das mensagens (entrada/saída). Gravado pelo webhook antes de existir contexto de cliente. */
class WhatsappMessage extends Model
{
    use HasUlids;

    protected $fillable = ['account_id', 'direction', 'wamid', 'phone_hash', 'body', 'intent', 'status', 'entry_id', 'used_ai'];

    protected function casts(): array
    {
        return ['body' => 'encrypted', 'used_ai' => 'boolean'];
    }
}
