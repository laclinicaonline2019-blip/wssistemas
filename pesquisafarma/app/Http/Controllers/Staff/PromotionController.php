<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Pharmacy;
use App\Models\Promotion;
use App\Models\Setting;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\Activity;
use App\Support\Geo;
use App\Support\Text;
use Illuminate\Http\Request;

/** Promoções do dia das farmácias: aprovação e divulgação para a lista de clientes. */
class PromotionController extends Controller
{
    public function index(Request $request)
    {
        return view('staff.promotions.index', [
            'promotions' => Promotion::query()->with('pharmacy.neighborhood')->when($request->status, fn ($q, $s) => $q->where('status', $s))->latest('id')->paginate(30)->withQueryString(),
            'pharmacies' => Pharmacy::orderBy('trade_name')->get(),
            'audience' => Customer::query()->where('accepts_marketing', true)->count(),
        ]);
    }

    public function store(Request $request)
    {
        $data = self::validatePromotion($request);
        $p = Promotion::create($data + ['status' => 'aprovada', 'created_by' => auth()->id(), 'approved_by' => auth()->id()]);
        Activity::log('promocao.criada', 'Promoção criada: '.$p->title, $p);

        return back()->with('ok', 'Promoção cadastrada.');
    }

    public static function validatePromotion(Request $request): array
    {
        $data = $request->validate([
            'pharmacy_id' => 'nullable|exists:pharmacies,id', 'title' => 'required|string|max:120', 'product_name' => 'nullable|string|max:150',
            'description' => 'nullable|string|max:1000', 'price' => 'nullable', 'original_price' => 'nullable', 'valid_from' => 'nullable|date', 'valid_until' => 'nullable|date',
        ]);
        $data['price'] = Text::money($data['price'] ?? null);
        $data['original_price'] = Text::money($data['original_price'] ?? null);

        return $data;
    }

    public function status(Request $request, Promotion $promotion)
    {
        $status = $request->validate(['status' => 'required|in:aprovada,recusada'])['status'];
        $promotion->update(['status' => $status, 'approved_by' => auth()->id()]);
        Activity::log('promocao.'.$status, "Promoção \"{$promotion->title}\" {$status}", $promotion);

        return back()->with('ok', 'Promoção '.$status.'.');
    }

    /** Divulga no WhatsApp para clientes que aceitaram receber ofertas (filtro opcional por bairro). */
    public function broadcast(Request $request, Promotion $promotion, WhatsAppService $wa)
    {
        abort_unless(in_array($promotion->status, ['aprovada', 'divulgada'], true), 422);
        $request->validate(['scope' => 'required|in:todos,cidade,bairro,proximos', 'city_id' => 'nullable|exists:cities,id']);
        $q = Customer::query()->where('accepts_marketing', true);
        $cityId = $request->integer('city_id') ?: $promotion->pharmacy?->city_id;
        if ($request->scope === 'cidade' && $cityId) {
            $q->where('city_id', $cityId);
        }
        if ($request->scope === 'bairro' && $promotion->pharmacy?->neighborhood_id) {
            $q->where('neighborhood_id', $promotion->pharmacy->neighborhood_id);
        }
        $customers = $q->get();
        if ($request->scope === 'proximos' && $promotion->pharmacy?->lat) {
            $origin = [$promotion->pharmacy->lat, $promotion->pharmacy->lng];
            $customers = $customers->filter(fn ($c) => ($d = Geo::distanceKm($origin, $c->coordinates())) !== null && $d <= (float) Setting::get('radius_far_km'));
        }
        $text = $promotion->load('pharmacy.neighborhood')->whatsappText();
        foreach ($customers as $c) {
            $wa->toCustomer($c, $text);
        }
        $promotion->update(['status' => 'divulgada', 'broadcast_at' => now(), 'broadcast_count' => $promotion->broadcast_count + $customers->count()]);
        Activity::log('promocao.divulgada', "Promoção \"{$promotion->title}\" enviada para {$customers->count()} cliente(s)", $promotion);

        return back()->with('ok', "Promoção enviada para {$customers->count()} cliente(s).");
    }
}
