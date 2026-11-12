<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['unit_price' => 'float', 'total' => 'float', 'is_suggestion' => 'boolean'];
}
