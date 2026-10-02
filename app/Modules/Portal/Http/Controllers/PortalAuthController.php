<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Security\PasswordRules;
use App\Http\Controllers\Controller;
use App\Modules\Portal\Services\PortalAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Entrada no portal: login, ativação/redefinição por link e "esqueci a senha". */
class PortalAuthController extends Controller
{
    public function __construct(private readonly PortalAccountService $accounts) {}

    public function show(Request $request): View|RedirectResponse
    {
        if ($request->attributes->get('portal_account_id')) {
            return redirect()->route('portal.home');
        }

        return view('portal.login', ['company' => $request->attributes->get('portal_company')]);
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['login' => ['required', 'string', 'max:190'], 'password' => ['required', 'string', 'max:200']], [], ['login' => 'CPF ou e-mail', 'password' => 'senha']);
        $result = $this->accounts->attempt($request->attributes->get('portal_company'), $data['login'], $data['password'], (string) $request->ip());

        if (! $result['account']) {
            throw ValidationException::withMessages(['login' => $result['error']]);
        }

        Auth::guard('patient')->login($result['account']);
        $request->session()->regenerate();
        $request->attributes->set('portal_account_id', $result['account']->id);
        app(AuditLogger::class)->record('portal.login', $result['account']);

        return redirect()->intended(route('portal.home'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('patient')->logout();
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();

        return redirect()->route('portal.login')->with('success', 'Você saiu do portal.');
    }

    public function showActivate(Request $request, string $token): View
    {
        $record = $this->accounts->tokenRecord($token);

        return view('portal.activate', ['company' => $request->attributes->get('portal_company'), 'record' => $record, 'token' => $token]);
    }

    public function activate(Request $request, string $token): RedirectResponse
    {
        $record = $this->accounts->tokenRecord($token);
        if (! $record) {
            return redirect()->route('portal.activate', $token);
        }

        $data = $request->validate([
            'password' => ['required', 'confirmed', PasswordRules::default()],
            'terms' => ['accepted'],
        ], ['terms.accepted' => 'É preciso aceitar os termos de uso do portal.'], ['password' => 'senha']);

        $account = $this->accounts->setPassword($record, $data['password']);
        Auth::guard('patient')->login($account);
        $request->session()->regenerate();

        return redirect()->route('portal.home')->with('success', 'Senha definida. Bem-vindo(a) ao portal!');
    }

    public function showForgot(Request $request): View
    {
        return view('portal.forgot', ['company' => $request->attributes->get('portal_company')]);
    }

    public function forgot(Request $request): RedirectResponse
    {
        $data = $request->validate(['cpf' => ['required', 'string', 'max:14'], 'birth_date' => ['required', 'date']], [], ['birth_date' => 'data de nascimento']);
        $this->accounts->requestReset($data['cpf'], substr($data['birth_date'], 0, 10));

        return redirect()->route('portal.login')->with('success', 'Se os dados conferirem com um acesso ativo, enviaremos um link para o e-mail cadastrado. Sem e-mail cadastrado? Peça um novo link na recepção.');
    }
}
