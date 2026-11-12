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
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $customers = Customer::query()->with('neighborhood')->withCount(['quotes', 'orders'])
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', '%'.Text::digits($s).'%')->orWhere('cpf', 'like', "%{$s}%")))
            ->when($request->bairro, fn ($q, $b) => $q->where('neighborhood_id', $b))
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
            'neighborhood_id' => 'nullable|exists:neighborhoods,id', 'city' => 'nullable|string|max:80', 'notes' => 'nullable|string|max:2000',
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

    public function adjustPoints(Request $request, Customer $customer, LoyaltyService $loyalty)
    {
        $data = $request->validate(['points' => 'required|integer|not_in:0', 'reason' => 'required|string|max:150']);
        $loyalty->add($customer, $data['points'], 'Ajuste: '.$data['reason'], [], auth()->id());
        Activity::log('fidelidade.ajuste', "Ajuste de {$data['points']} pts para {$customer->displayName()}", $customer, $data);

        return back()->with('ok', 'Pontos ajustados.');
    }
}
