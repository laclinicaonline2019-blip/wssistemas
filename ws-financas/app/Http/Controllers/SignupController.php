<?php

namespace App\Http\Controllers;

use App\Core\Security\PasswordRules;
use App\Core\Validation\Document;
use App\Models\Plan;
use App\Services\AccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** Cadastro self-service com teste grátis. */
class SignupController extends Controller
{
    public function show(Request $request): View
    {
        $plans = Plan::query()->where('active', true)->orderBy('sort')->get();

        return view('auth.signup', ['plans' => $plans, 'selected' => $request->query('plano')]);
    }

    public function store(Request $request, AccountService $accounts): RedirectResponse
    {
        $d = $request->validate([
            'person_type' => ['required', 'in:pf,pj'],
            'name' => ['required', 'string', 'max:160'],
            'document' => ['nullable', 'string', 'max:20', new Document],
            'company_name' => ['nullable', 'string', 'max:160'],
            'admin_name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'confirmed', PasswordRules::default()],
            'plan' => ['required', 'exists:plans,code'],
            'cycle' => ['required', 'in:monthly,yearly'],
            'accept' => ['accepted'],
        ], ['accept.accepted' => 'É preciso aceitar os termos de uso e a política de privacidade.']);

        $plan = Plan::query()->where('code', $d['plan'])->where('active', true)->firstOrFail();
        [, $user] = $accounts->register([
            'name' => $d['name'], 'person_type' => $d['person_type'], 'document' => ! empty($d['document']) ? preg_replace('/\D/', '', $d['document']) : null,
            'email' => $d['email'], 'phone' => $d['phone'] ?? null, 'company_name' => ($d['company_name'] ?? null) ?: $d['name'],
            'admin_name' => $d['admin_name'], 'admin_email' => $d['email'], 'password' => $d['password'],
        ], $plan, $d['cycle']);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route($user->requiresTwoFactor() ? 'security.2fa' : 'app')
            ->with('ok', 'Conta criada! Você tem '.config('wsf.billing.trial_days').' dias de teste grátis.');
    }
}
