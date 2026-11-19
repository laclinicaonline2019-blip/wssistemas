<?php

namespace App\Http\Controllers;

use App\Core\Security\PasswordRules;
use App\Models\Company;
use App\Models\Membership;
use App\Services\AccountService;
use App\Services\MailDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        return view('settings.users', [
            'list' => Membership::query()->with('user', 'companies')->orderBy('role')->get(),
            'allCompanies' => Company::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, AccountService $accounts): RedirectResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:160'], 'email' => ['required', 'email', 'max:190'], 'role' => ['required', Rule::in(array_keys(Membership::ROLES))],
            'all_companies' => ['boolean'], 'companies' => ['array'], 'companies.*' => ['string'], 'password' => ['nullable', PasswordRules::default()]]);
        [$user, , $created] = $accounts->addUser($d, $d['password'] ?? null);
        $mail = ['ok' => true];
        if ($created && empty($d['password'])) {
            $mail = app(MailDelivery::class)->sendPasswordLink($user->email, 'novo_usuario'); // o próprio usuário define a senha pelo link
        }
        if (! $mail['ok']) {
            return back()->with('error', 'Usuário criado, mas o e-mail para definir a senha não foi enviado. Avise o suporte ou peça para a pessoa usar "Esqueci minha senha" mais tarde.');
        }

        return back()->with('ok', $created ? 'Usuário criado. '.(empty($d['password']) ? 'Enviamos um e-mail para ele definir a senha.' : '') : 'Usuário existente vinculado à sua conta.');
    }

    public function update(Request $request, Membership $membership, AccountService $accounts): RedirectResponse
    {
        $d = $request->validate(['role' => ['required', Rule::in(array_keys(Membership::ROLES))], 'all_companies' => ['boolean'], 'companies' => ['array'], 'companies.*' => ['string'],
            'status' => ['required', 'in:active,inactive']]);
        $accounts->updateMembership($membership, $d);

        return back()->with('ok', 'Permissões atualizadas.');
    }
}
