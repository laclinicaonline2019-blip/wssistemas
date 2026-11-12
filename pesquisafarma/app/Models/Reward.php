<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Brinde trocável por pontos. */
class Reward extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];
}
