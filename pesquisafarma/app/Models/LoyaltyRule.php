<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Regra de pontuação: por valor gasto, por produto (palavra-chave) ou por farmácia parceira. */
class LoyaltyRule extends Model
{
    protected $guarded = [];

    protected $casts = ['points_per_real' => 'float', 'multiplier' => 'float', 'min_purchase' => 'float', 'active' => 'boolean', 'valid_until' => 'date'];

    public const TYPES = ['por_valor' => 'Pontos por R$ gasto', 'por_produto' => 'Bônus por produto', 'por_farmacia' => 'Multiplicador por farmácia'];
}
