<?php

namespace App\Services;

use App\Support\Text;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Consulta de CEP (ViaCEP) para identificar rua, bairro e cidade do cliente. */
class CepLookup
{
    /** @return array{cep:string, address:string, neighborhood:string, city:string, uf:string}|null */
    public function find(string $cep): ?array
    {
        $cep = Text::digits($cep);
        if (strlen($cep) !== 8 || ! config('pesquisafarma.cep_lookup')) {
            return null;
        }
        try {
            $r = Http::timeout(6)->get("https://viacep.com.br/ws/{$cep}/json/");
            if (! $r->successful() || $r->json('erro')) {
                return null;
            }

            return ['cep' => substr($cep, 0, 5).'-'.substr($cep, 5), 'address' => (string) $r->json('logradouro'),
                'neighborhood' => (string) $r->json('bairro'), 'city' => (string) $r->json('localidade'), 'uf' => (string) $r->json('uf')];
        } catch (Throwable) {
            return null;
        }
    }
}
