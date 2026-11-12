<?php

namespace App\Http\Controllers\Admin;

use App\Core\Audit\AuditLogger;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Planos configuráveis: preços, limites e recursos editáveis pelo proprietário. */
class PlanController extends Controller
{
    public function index(): View
    {
        return view('admin.plans', ['plans' => Plan::query()->withCount(['subscriptions' => fn ($q) => $q->whereIn('status', ['trialing', 'active', 'past_due'])])->orderBy('sort')->get()]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $plan = Plan::query()->create($this->data($request, null));
        $audit->log('plano.criar', $plan, $plan->only(['code', 'price_monthly_cents', 'price_yearly_cents']));

        return back()->with('ok', 'Plano criado.');
    }

    public function update(Request $request, Plan $plan, AuditLogger $audit): RedirectResponse
    {
        $before = $plan->only(['price_monthly_cents', 'price_yearly_cents', 'max_companies', 'max_users', 'features', 'active']);
        $plan->update($this->data($request, $plan));
        $audit->log('plano.editar', $plan, ['antes' => $before, 'depois' => $plan->only(array_keys($before))]);

        return back()->with('ok', 'Plano atualizado. Assinaturas existentes mantêm o preço contratado até a troca de plano.');
    }

    private function data(Request $request, ?Plan $plan): array
    {
        $d = $request->validate([
            'code' => [$plan ? 'nullable' : 'required', 'alpha_dash', 'max:40', Rule::unique('plans', 'code')->ignore($plan?->id)],
            'name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:500'],
            'price_monthly' => ['required', 'string', 'max:20'], 'price_yearly' => ['required', 'string', 'max:20'],
            'max_companies' => ['required', 'integer', 'min:1', 'max:1000'], 'max_users' => ['required', 'integer', 'min:1', 'max:1000'],
            'whatsapp_messages_month' => ['required', 'integer', 'min:0'], 'ai_calls_month' => ['required', 'integer', 'min:0'],
            'features' => ['array'], 'features.*' => [Rule::in(array_keys(Plan::FEATURES))], 'active' => ['boolean'], 'sort' => ['nullable', 'integer'],
        ]);
        $out = [
            'name' => $d['name'], 'description' => $d['description'] ?? null,
            'price_monthly_cents' => Money::parse($d['price_monthly']) ?? 0, 'price_yearly_cents' => Money::parse($d['price_yearly']) ?? 0,
            'max_companies' => $d['max_companies'], 'max_users' => $d['max_users'], 'whatsapp_messages_month' => $d['whatsapp_messages_month'], 'ai_calls_month' => $d['ai_calls_month'],
            'features' => array_fill_keys($d['features'] ?? [], true), 'active' => $request->boolean('active'), 'sort' => (int) ($d['sort'] ?? 0),
        ];
        if (! $plan) {
            $out['code'] = $d['code'];
        }

        return $out;
    }
}
