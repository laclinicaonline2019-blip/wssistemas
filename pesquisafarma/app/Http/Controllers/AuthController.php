<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function form()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $cred = $request->validate(['email' => 'required|email', 'password' => 'required']);
        $key = 'login:'.mb_strtolower($cred['email']);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['email' => 'Conta bloqueada por muitas tentativas. Tente em '.ceil(RateLimiter::availableIn($key) / 60).' min.'])->onlyInput('email');
        }
        if (! Auth::validate($cred + ['active' => true])) {
            RateLimiter::hit($key, 900);
            Activity::log('login.falha', 'Tentativa de login falhou: '.$cred['email']);

            return back()->withErrors(['email' => 'E-mail ou senha inválidos.'])->onlyInput('email');
        }
        RateLimiter::clear($key);
        $user = User::query()->where('email', $cred['email'])->first();
        // Verificação em duas etapas: senha certa, mas ainda falta o código do aplicativo
        if ($user->two_factor_confirmed_at) {
            $request->session()->put('2fa:user', $user->id);
            $request->session()->put('2fa:remember', $request->boolean('remember'));

            return redirect()->route('2fa.challenge');
        }
        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $user = $request->user();
        $user->update(['last_login_at' => now()]);
        Activity::log('login', 'Entrou no sistema');

        return redirect()->intended($user->isPharmacy() ? '/farmacia' : '/painel');
    }

    public function logout(Request $request)
    {
        if (Auth::check()) {
            Activity::log('logout', 'Saiu do sistema');
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
