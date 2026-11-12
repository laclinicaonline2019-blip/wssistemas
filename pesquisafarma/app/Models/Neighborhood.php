<?php

namespace App\Models;

use App\Support\Text;
use Illuminate\Database\Eloquent\Model;

class Neighborhood extends Model
{
    protected $guarded = [];

    protected $casts = ['lat' => 'float', 'lng' => 'float'];

    protected static function booted(): void
    {
        static::saving(fn (self $n) => $n->slug = Text::slug($n->name));
    }

    public function label(): string
    {
        return $this->name.($this->city ? ' — '.$this->city : '');
    }

    /** Localiza o bairro pelo nome (sem acentos/maiúsculas), opcionalmente na cidade. */
    public static function findByName(?string $name, ?string $city = null): ?self
    {
        if (! $name) {
            return null;
        }
        $q = static::query()->where('slug', Text::slug($name));
        if ($city) {
            $byCity = (clone $q)->where('city', $city)->first();
            if ($byCity) {
                return $byCity;
            }
        }

        return $q->first();
    }
}
