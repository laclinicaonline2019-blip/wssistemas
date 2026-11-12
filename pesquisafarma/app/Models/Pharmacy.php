<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pharmacy extends Model
{
    protected $guarded = [];

    protected $casts = [
        'lat' => 'float', 'lng' => 'float', 'delivers' => 'boolean', 'active' => 'boolean',
        'commission_percent' => 'float', 'delivery_radius_km' => 'float', 'delivery_base_fee' => 'float',
        'delivery_fee_per_km' => 'float', 'free_delivery_above' => 'float',
    ];

    public const CYCLES = ['semanal' => 'Semanal', 'mensal' => 'Mensal'];

    public function neighborhood(): BelongsTo
    {
        return $this->belongsTo(Neighborhood::class);
    }

    public function cityRef(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    protected static function booted(): void
    {
        // Cidade sempre coerente com o bairro escolhido
        static::saving(function ($m) {
            if ($m->neighborhood_id && ($n = Neighborhood::find($m->neighborhood_id)) && $n->city_id) {
                $m->city_id = $n->city_id;
            }
            if ($m->city_id && ($c = City::find($m->city_id))) {
                $m->city = $c->name;
            }
        });
    }

    public function zones(): HasMany
    {
        return $this->hasMany(PharmacyDeliveryZone::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(QuotePharmacy::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function fullAddress(): string
    {
        return trim(implode(', ', array_filter([
            trim(($this->address ?? '').' '.($this->number ?? '')), $this->neighborhood?->name, $this->city,
        ])));
    }

    public function mapsUrl(): string
    {
        $q = ($this->lat && $this->lng) ? $this->lat.','.$this->lng : $this->fullAddress();

        return 'https://www.google.com/maps/search/?api=1&query='.urlencode($q);
    }
}
