<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Extrato de pontos (crédito por compra, débito por troca, ajustes). */
class PointsEntry extends Model
{
    protected $table = 'points_ledger';

    protected $guarded = [];
}
