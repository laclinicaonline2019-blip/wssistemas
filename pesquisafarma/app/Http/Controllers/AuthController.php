<?php

namespace App\Http\Controllers;

use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function form()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $cred = $request->validate(['email' => 'required|email', 'password' => 'required']);
        if (! Auth::attempt($cred + ['active' => true], $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'E-mail ou senha inválidos.'])->onlyInput('email');
        }
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
