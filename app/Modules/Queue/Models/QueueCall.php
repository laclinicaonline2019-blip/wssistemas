<?php

namespace App\Modules\Queue\Models;

use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Chamada exibida no painel da TV (histórico). */
class QueueCall extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $fillable = ['branch_id', 'ticket_id', 'code', 'display_name', 'room_label', 'doctor_label', 'called_by', 'called_at'];

    protected function casts(): array
    {
        return ['called_at' => 'datetime'];
    }
}
