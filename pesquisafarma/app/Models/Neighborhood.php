<?php

namespace App\Models;

use App\Support\Text;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Neighborhood extends Model
{
    protected $guarded = [];

    protected $casts = ['lat' => 'float', 'lng' => 'float'];

    protected static function booted(): void
    {
        static::saving(function (self $n) {
            $n->slug = Text::slug($n->name);
            if ($n->city_id && ($c = City::find($n->city_id))) {
                $n->city = $c->name;
                $n->uf = $c->uf;
            }
        });
    }

    public function cityRef(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function label(): string
    {
        return $this->name.($this->city ? ' — '.$this->city.($this->uf ? '/'.$this->uf : '') : '');
    }

    /** Localiza o bairro pelo nome (sem acentos/maiúsculas), opcionalmente na cidade. */
    public static function findByName(?string $name, City|string|null $city = null): ?self
    {
        if (! $name) {
            return null;
        }
        $q = static::query()->where('slug', Text::slug($name));
        if ($city instanceof City) {
            return $q->where('city_id', $city->id)->first();
        }
        if ($city) {
            $byCity = (clone $q)->where('city', $city)->first();
            if ($byCity) {
                return $byCity;
            }
        }

        return $q->first();
    }

    /** Bairros com esse nome em cidades diferentes (o robô pergunta a cidade). */
    public static function allByName(string $name)
    {
        return static::query()->where('slug', Text::slug($name))->get();
    }
}
