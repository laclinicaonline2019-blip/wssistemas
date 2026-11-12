<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Neighborhood;
use Illuminate\Http\Request;

/** Bairros com coordenadas (centro) — base da busca por farmácias mais próximas. */
class NeighborhoodController extends Controller
{
    public function index(Request $request)
    {
        return view('staff.neighborhoods', ['neighborhoods' => Neighborhood::query()
            ->when($request->q, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->when($request->sem_coordenadas, fn ($q) => $q->whereNull('lat'))
            ->orderBy('city')->orderBy('name')->paginate(50)->withQueryString()]);
    }

    private function rules(): array
    {
        return ['name' => 'required|string|max:100', 'city' => 'nullable|string|max:80', 'uf' => 'nullable|string|size:2', 'lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180'];
    }

    public function store(Request $request)
    {
        Neighborhood::create(array_map(fn ($v) => $v ?? '', $request->validate($this->rules())) + ['lat' => $request->lat ?: null, 'lng' => $request->lng ?: null]);

        return back()->with('ok', 'Bairro cadastrado.');
    }

    public function update(Request $request, Neighborhood $neighborhood)
    {
        $data = $request->validate($this->rules());
        $neighborhood->update(['name' => $data['name'], 'city' => $data['city'] ?? '', 'uf' => $data['uf'] ?? '', 'lat' => $data['lat'] ?? null, 'lng' => $data['lng'] ?? null]);

        return back()->with('ok', 'Bairro atualizado.');
    }

    /** CSV: nome;cidade;uf;latitude;longitude */
    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|max:2048']);
        $n = 0;
        foreach (preg_split('/\r?\n/', (string) $request->file('file')->get()) as $i => $line) {
            $c = str_getcsv($line, str_contains($line, ';') ? ';' : ',');
            if (count($c) < 2 || ($i === 0 && ! is_numeric(str_replace(',', '.', $c[3] ?? '0')))) {
                continue;
            }
            $lat = isset($c[3]) && $c[3] !== '' ? (float) str_replace(',', '.', $c[3]) : null;
            $lng = isset($c[4]) && $c[4] !== '' ? (float) str_replace(',', '.', $c[4]) : null;
            $nb = Neighborhood::findByName(trim($c[0]), trim($c[1])) ?? new Neighborhood;
            $nb->fill(['name' => trim($c[0]), 'city' => trim($c[1]), 'uf' => strtoupper(trim($c[2] ?? '')), 'lat' => $lat ?? $nb->lat, 'lng' => $lng ?? $nb->lng])->save();
            $n++;
        }

        return back()->with('ok', "{$n} bairro(s) importado(s).");
    }
}
