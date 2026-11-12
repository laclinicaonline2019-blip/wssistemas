<?php

namespace App\Models;

use App\Support\Text;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Município atendido (com UF). Bairros, farmácias e clientes pertencem a uma cidade. */
class City extends Model
{
    protected $guarded = [];

    protected $casts = ['lat' => 'float', 'lng' => 'float', 'active' => 'boolean'];

    public const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    protected static function booted(): void
    {
        static::saving(function (self $c) {
            $c->slug = Text::slug($c->name);
            $c->uf = strtoupper((string) $c->uf);
        });
        // Mantém o nome/UF copiados nos bairros, farmácias e clientes
        static::updated(function (self $c) {
            Neighborhood::query()->where('city_id', $c->id)->update(['city' => $c->name, 'uf' => $c->uf]);
            Pharmacy::query()->where('city_id', $c->id)->update(['city' => $c->name]);
            Customer::query()->where('city_id', $c->id)->update(['city' => $c->name]);
        });
    }

    public function neighborhoods(): HasMany
    {
        return $this->hasMany(Neighborhood::class);
    }

    public function pharmacies(): HasMany
    {
        return $this->hasMany(Pharmacy::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function label(): string
    {
        return $this->name.'/'.$this->uf;
    }

    /** Encontra ou cria a cidade (ViaCEP, importação, robô). */
    public static function findOrCreateByName(string $name, string $uf = ''): self
    {
        $q = static::query()->where('slug', Text::slug($name));
        if ($uf !== '') {
            return (clone $q)->where('uf', strtoupper($uf))->first() ?? static::create(['name' => trim($name), 'uf' => $uf]);
        }

        return $q->first() ?? static::create(['name' => trim($name), 'uf' => '']);
    }

    /** "Campinas", "Campinas/SP", "Campinas - SP" → cidade cadastrada (ou null). */
    public static function match(string $text): ?self
    {
        $uf = null;
        if (preg_match('/^(.*?)[\s\/\-,]+([A-Za-z]{2})$/u', trim($text), $m) && in_array(strtoupper($m[2]), self::UFS, true)) {
            [$text, $uf] = [$m[1], strtoupper($m[2])];
        }

        return static::query()->where('slug', Text::slug($text))->when($uf, fn ($q) => $q->where('uf', $uf))->first();
    }
}
