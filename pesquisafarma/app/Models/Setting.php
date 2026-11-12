<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** Configurações gerais editáveis pelo administrador (chave/valor com cache). */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public const DEFAULTS = [
        'radius_near_km' => '3',
        'radius_far_km' => '15',
        'response_minutes' => '20',
        'followup_minutes' => '180',
        'points_per_real' => '1',
        // Incentivo para o cliente cotar com a Pesquisa Farma (e não comprar por conta própria)
        'points_quote_multiplier' => '2',     // compra pela cotação: pontos em dobro
        'points_direct_multiplier' => '0.5',  // compra direta no balcão, sem cotação: metade
        'prize_requires_quote' => '1',        // premiação do mês só para compras pela cotação
        'portal_enabled' => '1',
        'loyalty_enabled' => '1',
        'max_pharmacies' => '15',
        'company_name' => 'Pesquisa Farma',
        'company_whatsapp' => '',
        'ai_enabled' => '1',
        'welcome_message' => 'Olá! 👋 Aqui é a *Pesquisa Farma*. Pesquisamos o preço do seu medicamento em várias farmácias e enviamos a *melhor cotação*!',
    ];

    public static function get(string $key, ?string $default = null): ?string
    {
        $all = Cache::remember('pf_settings', 300, fn () => static::query()->pluck('value', 'key')->all());

        return $all[$key] ?? $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('pf_settings');
    }
}
