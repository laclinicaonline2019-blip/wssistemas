<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Neighborhood;
use App\Models\Reward;
use App\Services\LoyaltyService;
use App\Support\Activity;
use App\Support\Text;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $customers = Customer::query()->with('neighborhood')->withCount(['quotes', 'orders'])
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', '%'.Text::digits($s).'%')->orWhere('cpf', 'like', "%{$s}%")))
            ->when($request->bairro, fn ($q, $b) => $q->where('neighborhood_id', $b))
            ->when($request->cidade, fn ($q, $c) => $q->where('city_id', $c))
            ->when($request->uf, fn ($q, $uf) => $q->whereHas('cityRef', fn ($c) => $c->where('uf', $uf)))
            ->when($request->incompleto, fn ($q) => $q->whereIn('bot_state', Customer::REGISTRATION_STATES))
            ->latest('id')->paginate(30)->withQueryString();

        return view('staff.customers.index', ['customers' => $customers, 'neighborhoods' => Neighborhood::orderBy('name')->get()]);
    }

    public function create()
    {
        return view('staff.customers.form', ['customer' => new Customer, 'neighborhoods' => Neighborhood::orderBy('name')->get()]);
    }

    private function validated(Request $request, ?Customer $c = null): array
    {
        $request->merge(['phone' => Text::phone($request->phone), 'cpf' => $request->cpf ? Text::digits($request->cpf) : null]);
        $data = $request->validate([
            'name' => 'required|string|max:120', 'phone' => ['required', 'string', 'min:12', Rule::unique('customers')->ignore($c?->id)],
            'cpf' => ['nullable', 'digits:11', Rule::unique('customers')->ignore($c?->id)], 'email' => 'nullable|email', 'birth_date' => 'nullable|date',
            'cep' => 'nullable|string|max:9', 'address' => 'nullable|string|max:200', 'number' => 'nullable|string|max:20', 'complement' => 'nullable|string|max:120',
            'city_id' => 'nullable|exists:cities,id',
            'neighborhood_id' => ['nullable', Rule::exists('neighborhoods', 'id')->when($request->filled('city_id'), fn ($r) => $r->where('city_id', $request->integer('city_id')))], 'notes' => 'nullable|string|max:2000',
        ]);
        $data['accepts_marketing'] = $request->boolean('accepts_marketing');
        $data['loyalty_enabled'] = $request->boolean('loyalty_enabled');
        if ($request->boolean('lgpd') && ! $c?->lgpd_consent_at) {
            $data['lgpd_consent_at'] = now();
        }

        return $data;
    }

    public function store(Request $request)
    {
        $c = Customer::create($this->validated($request) + ['origin' => 'painel']);
        Activity::log('cliente.criado', 'Cliente '.$c->name.' cadastrado', $c);

        return redirect()->route('customers.show', $c)->with('ok', 'Cliente cadastrado.');
    }

    public function show(Customer $customer)
    {
        $customer->load('neighborhood');

        return view('staff.customers.show', [
            'customer' => $customer,
            'quotes' => $customer->quotes()->with('order.pharmacy')->latest('id')->get(),
            'orders' => $customer->orders()->with('pharmacy')->latest('id')->get(),
            'receipts' => $customer->receipts()->with('pharmacy')->latest('id')->get(),
            'points' => $customer->points()->latest('id')->limit(50)->get(),
            'redemptions' => $customer->redemptions()->with('reward')->latest('id')->get(),
            'rewards' => Reward::query()->where('active', true)->orderBy('points_cost')->get(),
            'card' => app(LoyaltyService::class)->card($customer),
            'stats' => [
                'orders_total' => $customer->orders()->where('status', '!=', 'cancelado')->sum('total'),
                'receipts_total' => $customer->receipts()->where('status', 'conferida')->sum('total'),
                'feedback' => $customer->quotes()->whereNotNull('feedback_rating')->avg('feedback_rating'),
            ],
        ]);
    }

    public function edit(Customer $customer)
    {
        return view('staff.customers.form', ['customer' => $customer, 'neighborhoods' => Neighborhood::orderBy('name')->get()]);
    }

    public function update(Request $request, Customer $customer)
    {
        $data = $this->validated($request, $customer);
        if ($request->boolean('finish_registration')) {
            $data['bot_state'] = null;
            $data['bot_data'] = null;
        }
        $customer->update($data);
        Activity::log('cliente.editado', 'Cadastro de '.$customer->name.' atualizado', $customer);

        return redirect()->route('customers.show', $customer)->with('ok', 'Cadastro atualizado.');
    }

    /** LGPD: exporta todos os dados do cliente (direito de acesso/portabilidade). */
    public function export(Customer $customer)
    {
        Activity::log('lgpd.exportacao', 'Dados exportados: '.$customer->displayName(), $customer);
        $data = [
            'cliente' => $customer->makeHidden(['portal_code', 'bot_data'])->toArray(),
            'cotacoes' => $customer->quotes()->with('items')->get()->toArray(),
            'pedidos' => $customer->orders()->with('items')->get()->toArray(),
            'notas_fiscais' => $customer->receipts()->with('items')->get()->makeHidden(['ai_data', 'file_path'])->toArray(),
            'pontos' => $customer->points()->get()->toArray(),
            'trocas' => $customer->redemptions()->get()->toArray(),
            'mensagens' => $customer->messages()->get(['direction', 'type', 'body', 'created_at'])->toArray(),
            'gerado_em' => now()->toIso8601String(),
        ];

        return response()->streamDownload(fn () => print (json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)), 'dados-cliente-'.$customer->id.'.json', ['Content-Type' => 'application/json']);
    }

    /**
     * LGPD: anonimiza o cliente (direito de eliminação). Apaga dados pessoais, conversas e arquivos;
     * mantém só os valores de pedidos/notas, que são registros financeiros e fiscais da operação.
     */
    public function anonymize(Request $request, Customer $customer)
    {
        $request->validate(['confirm' => 'required|in:ANONIMIZAR']);
        $disk = Storage::disk('local');
        foreach ($customer->messages()->whereNotNull('media_path')->pluck('media_path') as $path) {
            $disk->delete($path);
        }
        $customer->messages()->delete();
        foreach ($customer->quotes()->whereNotNull('attachment_path')->get() as $q) {
            $disk->delete($q->attachment_path);
        }
        $customer->quotes()->update(['raw_text' => null, 'attachment_path' => null, 'delivery_address' => null, 'feedback_comment' => null]);
        foreach ($customer->receipts()->whereNotNull('file_path')->get() as $r) {
            $disk->delete($r->file_path);
        }
        $customer->receipts()->update(['file_path' => null, 'ai_data' => null]);
        $customer->update([
            'name' => 'Cliente anonimizado #'.$customer->id, 'phone' => 'anon-'.$customer->id, 'cpf' => null, 'email' => null, 'birth_date' => null,
            'cep' => null, 'address' => null, 'number' => null, 'complement' => null, 'lat' => null, 'lng' => null, 'notes' => null,
            'accepts_marketing' => false, 'portal_code' => null, 'bot_state' => null, 'bot_data' => null, 'anonymized_at' => now(),
        ]);
        Activity::log('lgpd.anonimizacao', 'Cliente #'.$customer->id.' anonimizado a pedido', $customer);

        return redirect()->route('customers.show', $customer)->with('ok', 'Cliente anonimizado. Dados pessoais, conversas e arquivos foram apagados.');
    }

    public function adjustPoints(Request $request, Customer $customer, LoyaltyService $loyalty)
    {
        $data = $request->validate(['points' => 'required|integer|not_in:0', 'reason' => 'required|string|max:150']);
        $loyalty->add($customer, $data['points'], 'Ajuste: '.$data['reason'], [], auth()->id());
        Activity::log('fidelidade.ajuste', "Ajuste de {$data['points']} pts para {$customer->displayName()}", $customer, $data);

        return back()->with('ok', 'Pontos ajustados.');
    }
}
