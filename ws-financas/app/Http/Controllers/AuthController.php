<?php

namespace App\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Security\LoginService;
use App\Core\Security\PasswordRules;
use App\Core\Security\TwoFactorService;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function __construct(private readonly LoginService $login, private readonly TwoFactorService $twoFactor, private readonly AuditLogger $audit) {}

    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190'], 'password' => ['required', 'string', 'max:200']]);
        $result = $this->login->attempt($data['email'], $data['password']);
        if (! $result['ok']) {
            return back()->withInput($request->only('email'))->with('error', $result['message']);
        }
        $user = $result['user'];

        if ($user->hasTwoFactor()) {
            $request->session()->put('2fa:user', $user->id);
            $request->session()->put('2fa:remember', $request->boolean('remember'));

            return redirect()->route('login.2fa');
        }

        return $this->complete($request, $user, $request->boolean('remember'));
    }

    public function showTwoFactor(Request $request): View|RedirectResponse
    {
        return $request->session()->has('2fa:user') ? view('auth.two-factor') : redirect()->route('login');
    }

    public function twoFactor(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:20']]);
        $user = User::query()->find($request->session()->get('2fa:user'));
        if (! $user) {
            return redirect()->route('login');
        }
        $code = (string) $request->input('code');
        $ok = strlen(preg_replace('/\D/', '', $code)) === 6
            ? $this->twoFactor->verify($user, (string) $user->two_factor_secret, $code)
            : $this->twoFactor->useRecoveryCode($user, $code);
        if (! $ok) {
            $this->audit->log('auth.2fa_falha', $user, userId: $user->id);

            return back()->with('error', 'Código inválido.');
        }
        $remember = (bool) $request->session()->pull('2fa:remember');
        $request->session()->forget('2fa:user');

        return $this->complete($request, $user, $remember);
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->audit->log('auth.sair', $request->user());
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('ok', 'Você saiu com segurança.');
    }

    public function showForgot(): View
    {
        return view('auth.forgot');
    }

    public function forgot(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:190']]);
        Password::sendResetLink(['email' => mb_strtolower($request->input('email'))]);

        // resposta neutra: não revela se o e-mail existe
        return back()->with('ok', 'Pronto! Se este e-mail estiver cadastrado, enviamos para ele um link para criar uma nova senha (válido por 60 minutos). Confira também a caixa de spam.');
    }

    public function showReset(Request $request, string $token): View
    {
        return view('auth.reset', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate(['token' => 'required', 'email' => ['required', 'email'], 'password' => ['required', 'confirmed', PasswordRules::default()]]);
        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'failed_logins' => 0, 'locked_until' => null])->setRememberToken(null);
            $user->save();
            event(new PasswordReset($user));
            $this->audit->log('auth.senha_redefinida', $user, userId: $user->id);
        });

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('ok', 'Senha alterada. Entre com a nova senha.')
            : back()->with('error', 'Link inválido ou expirado. Peça um novo.');
    }

    private function complete(Request $request, User $user, bool $remember): RedirectResponse
    {
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $this->audit->log('auth.entrar', $user, userId: $user->id);

        if ($user->is_platform_admin && ! $user->memberships()->withoutGlobalScopes()->exists()) {
            return redirect()->intended(route('admin.dashboard'));
        }

        return redirect()->intended(route('app'));
    }
}
