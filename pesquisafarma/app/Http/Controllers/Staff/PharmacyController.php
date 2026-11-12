<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\CommissionStatement;
use App\Models\Neighborhood;
use App\Models\Pharmacy;
use App\Models\PharmacyDeliveryZone;
use App\Models\User;
use App\Services\ReportService;
use App\Support\Activity;
use App\Support\Text;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Cadastro das farmácias com a regra de comissão (% e ciclo) e as regras de entrega. */
class PharmacyController extends Controller
{
    public function index(Request $request)
    {
        $pharmacies = Pharmacy::query()->with('neighborhood')->withCount(['orders', 'dispatches'])
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('trade_name', 'like', "%{$s}%")->orWhere('cnpj', 'like', "%{$s}%")))
            ->orderBy('trade_name')->paginate(30)->withQueryString();

        return view('staff.pharmacies.index', compact('pharmacies'));
    }

    public function create()
    {
        return view('staff.pharmacies.form', ['pharmacy' => new Pharmacy(['commission_percent' => 5, 'commission_cycle' => 'mensal', 'delivers' => true, 'delivery_radius_km' => 5, 'delivery_eta_min' => 60, 'active' => true]),
            'neighborhoods' => Neighborhood::orderBy('name')->get()]);
    }

    private function validated(Request $request, ?Pharmacy $p = null): array
    {
        $request->merge(['cnpj' => $request->cnpj ? Text::digits($request->cnpj) : null, 'whatsapp' => $request->whatsapp ? Text::phone($request->whatsapp) : null]);
        $data = $request->validate([
            'trade_name' => 'required|string|max:120', 'legal_name' => 'nullable|string|max:160', 'cnpj' => ['nullable', 'digits:14', Rule::unique('pharmacies')->ignore($p?->id)],
            'phone' => 'nullable|string|max:20', 'whatsapp' => 'nullable|string|max:20', 'email' => 'nullable|email', 'responsible' => 'nullable|string|max:120',
            'cep' => 'nullable|string|max:9', 'address' => 'nullable|string|max:200', 'number' => 'nullable|string|max:20', 'neighborhood_id' => 'nullable|exists:neighborhoods,id',
            'city' => 'nullable|string|max:80', 'lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180',
            'commission_percent' => 'required|numeric|min:0|max:100', 'commission_cycle' => 'required|in:semanal,mensal',
            'delivery_radius_km' => 'nullable|numeric|min:0|max:100', 'delivery_base_fee' => 'nullable', 'delivery_fee_per_km' => 'nullable', 'free_delivery_above' => 'nullable',
            'delivery_eta_min' => 'nullable|integer|min:0|max:1440', 'opening_hours' => 'nullable|string|max:200', 'payment_methods' => 'nullable|string|max:200', 'notes' => 'nullable|string|max:2000',
        ]);
        foreach (['delivery_base_fee', 'delivery_fee_per_km'] as $k) {
            $data[$k] = Text::money($data[$k] ?? null) ?? 0;
        }
        $data['free_delivery_above'] = Text::money($data['free_delivery_above'] ?? null);
        $data['delivery_radius_km'] ??= 0;
        $data['delivery_eta_min'] ??= 60;
        $data['delivers'] = $request->boolean('delivers');
        $data['active'] = $request->boolean('active');

        return $data;
    }

    public function store(Request $request)
    {
        $p = Pharmacy::create($this->validated($request));
        Activity::log('farmacia.criada', "Farmácia {$p->trade_name} cadastrada ({$p->commission_percent}% {$p->commission_cycle})", $p);

        return redirect()->route('pharmacies.show', $p)->with('ok', 'Farmácia cadastrada. Crie o acesso ao painel e as zonas de entrega.');
    }

    public function show(Pharmacy $pharmacy)
    {
        $svc = new ReportService(CarbonImmutable::today()->startOfMonth(), CarbonImmutable::today()->endOfDay());

        return view('staff.pharmacies.show', [
            'pharmacy' => $pharmacy->load(['neighborhood', 'zones.neighborhood', 'users']),
            'neighborhoods' => Neighborhood::orderBy('name')->get(),
            'month' => $svc->byPharmacy($pharmacy->id)->first(),
            'statements' => $pharmacy->hasMany(CommissionStatement::class)->latest('period_end')->limit(12)->get(),
        ]);
    }

    public function edit(Pharmacy $pharmacy)
    {
        return view('staff.pharmacies.form', ['pharmacy' => $pharmacy, 'neighborhoods' => Neighborhood::orderBy('name')->get()]);
    }

    public function update(Request $request, Pharmacy $pharmacy)
    {
        $before = $pharmacy->only(['commission_percent', 'commission_cycle']);
        $pharmacy->update($this->validated($request, $pharmacy));
        Activity::log('farmacia.editada', "Farmácia {$pharmacy->trade_name} atualizada", $pharmacy, ['antes' => $before, 'depois' => $pharmacy->only(['commission_percent', 'commission_cycle'])]);

        return redirect()->route('pharmacies.show', $pharmacy)->with('ok', 'Farmácia atualizada.');
    }

    public function saveZone(Request $request, Pharmacy $pharmacy)
    {
        $data = $request->validate(['neighborhood_id' => 'required|exists:neighborhoods,id', 'fee' => 'nullable', 'eta_min' => 'nullable|integer|min:0']);
        PharmacyDeliveryZone::updateOrCreate(['pharmacy_id' => $pharmacy->id, 'neighborhood_id' => $data['neighborhood_id']],
            ['fee' => Text::money($data['fee'] ?? 0) ?? 0, 'eta_min' => $data['eta_min'] ?? null]);

        return back()->with('ok', 'Zona de entrega salva.');
    }

    public function deleteZone(Pharmacy $pharmacy, PharmacyDeliveryZone $zone)
    {
        abort_unless($zone->pharmacy_id === $pharmacy->id, 404);
        $zone->delete();

        return back()->with('ok', 'Zona removida.');
    }

    public function addUser(Request $request, Pharmacy $pharmacy)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|unique:users,email', 'password' => ['required', Password::min(8)]]);
        $u = User::create($data + ['role' => 'farmacia', 'pharmacy_id' => $pharmacy->id]);
        Activity::log('usuario.criado', "Acesso de farmácia criado: {$u->email} ({$pharmacy->trade_name})", $u);

        return back()->with('ok', 'Acesso ao painel criado.');
    }
}
