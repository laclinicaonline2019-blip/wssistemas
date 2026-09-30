<?php

namespace App\Core\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Consulta de endereço por CEP (ViaCEP). Feita pelo servidor — o navegador só
 * fala com o próprio sistema (CSP connect-src 'self'). Falhas não bloqueiam o
 * cadastro: o usuário preenche manualmente.
 */
class CepLookup
{
    /** @return array{zip_code: string, street: ?string, district: ?string, city: ?string, state: ?string}|null */
    public function find(string $cep): ?array
    {
        $cep = Format::digits($cep);

        if ($cep === null || strlen($cep) !== 8) {
            return null;
        }

        return Cache::remember("cep:{$cep}", now()->addDays(30), function () use ($cep) {
            try {
                $response = Http::timeout(4)->acceptJson()->get("https://viacep.com.br/ws/{$cep}/json/");
            } catch (Throwable) {
                return null;
            }

            if (! $response->ok() || $response->json('erro')) {
                return null;
            }

            return [
                'zip_code' => $cep,
                'street' => $response->json('logradouro') ?: null,
                'district' => $response->json('bairro') ?: null,
                'city' => $response->json('localidade') ?: null,
                'state' => $response->json('uf') ?: null,
            ];
        });
    }
}
