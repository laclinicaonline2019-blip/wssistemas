<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Neighborhood;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cidades (com UF) e bairros — o sistema atende vários municípios e estados.
 * O centro de cada bairro (latitude/longitude) é a base da busca pelas farmácias mais próximas.
 */
class NeighborhoodController extends Controller
{
    public function index(Request $request)
    {
        $cities = City::query()->withCount(['neighborhoods', 'pharmacies', 'customers'])
            ->when($request->uf, fn ($q, $uf) => $q->where('uf', $uf))->orderBy('uf')->orderBy('name')->get();

        return view('staff.neighborhoods', [
            'cities' => $cities,
            'allCities' => City::query()->orderBy('uf')->orderBy('name')->get(),
            'neighborhoods' => Neighborhood::query()
                ->when($request->cidade, fn ($q, $c) => $q->where('city_id', $c))
                ->when($request->uf, fn ($q, $uf) => $q->where('uf', $uf))
                ->when($request->q, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
                ->when($request->sem_coordenadas, fn ($q) => $q->whereNull('lat'))
                ->when($request->sem_cidade, fn ($q) => $q->whereNull('city_id'))
                ->orderBy('uf')->orderBy('city')->orderBy('name')->paginate(50)->withQueryString(),
        ]);
    }

    public function storeCity(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'uf' => ['required', Rule::in(City::UFS)], 'lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180']);
        $city = City::findOrCreateByName($data['name'], $data['uf']);
        $city->update(['lat' => $data['lat'] ?? $city->lat, 'lng' => $data['lng'] ?? $city->lng]);
        Activity::log('cidade.criada', 'Cidade cadastrada: '.$city->label(), $city);

        return redirect()->route('neighborhoods.index', ['cidade' => $city->id])->with('ok', "Cidade {$city->label()} cadastrada. Agora cadastre os bairros.");
    }

    public function updateCity(Request $request, City $city)
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'uf' => ['required', Rule::in(City::UFS)], 'lat' => 'nullable|numeric', 'lng' => 'nullable|numeric']);
        $city->update($data + ['active' => $request->boolean('active')]);

        return back()->with('ok', 'Cidade atualizada.');
    }

    private function rules(): array
    {
        return ['name' => 'required|string|max:100', 'city_id' => 'required|exists:cities,id', 'lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180'];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $city = City::find($data['city_id']);
        if (Neighborhood::findByName($data['name'], $city)) {
            return back()->with('error', "O bairro {$data['name']} já existe em {$city->label()}.");
        }
        Neighborhood::create(['name' => $data['name'], 'city_id' => $city->id, 'lat' => $data['lat'] ?? null, 'lng' => $data['lng'] ?? null]);

        return back()->with('ok', 'Bairro cadastrado em '.$city->label().'.');
    }

    public function update(Request $request, Neighborhood $neighborhood)
    {
        $data = $request->validate($this->rules());
        $neighborhood->update(['name' => $data['name'], 'city_id' => $data['city_id'], 'lat' => $data['lat'] ?? null, 'lng' => $data['lng'] ?? null]);

        return back()->with('ok', 'Bairro atualizado.');
    }

    /** CSV: bairro;cidade;uf;latitude;longitude — cria as cidades que não existirem. */
    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|max:4096']);
        $n = 0;
        foreach (preg_split('/\r?\n/', (string) $request->file('file')->get()) as $i => $line) {
            $c = str_getcsv($line, str_contains($line, ';') ? ';' : ',');
            if (count($c) < 3 || trim($c[0]) === '' || ($i === 0 && preg_match('/bairro/i', $c[0]))) {
                continue;
            }
            $uf = strtoupper(trim($c[2]));
            if (! in_array($uf, City::UFS, true)) {
                continue;
            }
            $city = City::findOrCreateByName(trim($c[1]), $uf);
            $lat = isset($c[3]) && trim($c[3]) !== '' ? (float) str_replace(',', '.', $c[3]) : null;
            $lng = isset($c[4]) && trim($c[4]) !== '' ? (float) str_replace(',', '.', $c[4]) : null;
            $nb = Neighborhood::findByName(trim($c[0]), $city) ?? new Neighborhood;
            $nb->fill(['name' => trim($c[0]), 'city_id' => $city->id, 'lat' => $lat ?? $nb->lat, 'lng' => $lng ?? $nb->lng])->save();
            $n++;
        }

        return back()->with('ok', "{$n} bairro(s) importado(s).");
    }
}
