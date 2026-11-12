<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use App\Support\Activity;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

/** Verificação em duas etapas (aplicativo autenticador: Google Authenticator, Microsoft Authenticator, Authy). */
class TwoFactorController extends Controller
{
    public function __construct(private readonly Google2FA $g2fa) {}

    /** Depois da senha: pede o código de 6 dígitos. */
    public function challenge(Request $request)
    {
        abort_unless($request->session()->has('2fa:user'), 403);

        return view('auth.two-factor');
    }

    public function verifyChallenge(Request $request)
    {
        $id = $request->session()->get('2fa:user');
        abort_unless($id, 403);
        $request->validate(['code' => 'required|digits:6']);
        $key = '2fa:'.$id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $request->session()->forget('2fa:user');

            return redirect('/login')->withErrors(['email' => 'Muitas tentativas no código. Entre novamente em alguns minutos.']);
        }
        $user = User::findOrFail($id);
        if (! $user->active || ! $this->g2fa->verifyKey((string) $user->two_factor_secret, $request->code, 1)) {
            RateLimiter::hit($key, 900);
            Activity::log('login.2fa_falha', 'Código de verificação inválido', $user, userId: $user->id);

            return back()->withErrors(['code' => 'Código inválido.']);
        }
        RateLimiter::clear($key);
        $remember = (bool) $request->session()->pull('2fa:remember');
        $request->session()->forget('2fa:user');
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $user->update(['last_login_at' => now()]);
        Activity::log('login', 'Entrou no sistema (com verificação em duas etapas)');

        return redirect()->intended($user->isPharmacy() ? '/farmacia' : '/painel');
    }

    /** Tela "Minha segurança": ativar/desativar. */
    public function setup(Request $request)
    {
        $user = $request->user();
        $qr = null;
        if (! $user->two_factor_confirmed_at) {
            $secret = $request->session()->get('2fa:new_secret') ?? $this->g2fa->generateSecretKey();
            $request->session()->put('2fa:new_secret', $secret);
            $url = $this->g2fa->getQRCodeUrl('Pesquisa Farma', $user->email, $secret);
            $qr = (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString($url);
        }

        return view('auth.two-factor-setup', ['user' => $user, 'qr' => $qr, 'secret' => $secret ?? null]);
    }

    public function enable(Request $request)
    {
        $request->validate(['code' => 'required|digits:6']);
        $secret = (string) $request->session()->get('2fa:new_secret');
        if ($secret === '' || ! $this->g2fa->verifyKey($secret, $request->code, 1)) {
            return back()->withErrors(['code' => 'Código inválido. Confira o horário do celular e tente de novo.']);
        }
        $request->user()->update(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()]);
        $request->session()->forget('2fa:new_secret');
        Activity::log('seguranca.2fa_ativada', 'Verificação em duas etapas ativada');

        return redirect()->intended('/')->with('ok', 'Verificação em duas etapas ativada.');
    }

    public function disable(Request $request)
    {
        $request->validate(['password' => 'required|current_password']);
        abort_if($request->user()->isAdmin() && Setting::get('require_2fa_admin') === '1', 422, 'Administradores precisam manter a verificação em duas etapas.');
        $request->user()->update(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);
        Activity::log('seguranca.2fa_desativada', 'Verificação em duas etapas desativada');

        return back()->with('ok', 'Verificação em duas etapas desativada.');
    }
}
