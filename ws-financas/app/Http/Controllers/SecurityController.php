<?php

namespace App\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Security\PasswordRules;
use App\Core\Security\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class SecurityController extends Controller
{
    public function __construct(private readonly TwoFactorService $tf, private readonly AuditLogger $audit) {}

    public function show(Request $request): View
    {
        return view('security.show', ['user' => $request->user()]);
    }

    public function twoFactor(Request $request): View
    {
        $user = $request->user();
        $secret = $request->session()->get('2fa:setup') ?? $this->tf->generateSecret();
        $request->session()->put('2fa:setup', $secret);

        return view('security.two-factor', ['user' => $user, 'secret' => $secret, 'qr' => $this->tf->qrCodeSvg($this->tf->otpauthUrl($user, $secret))]);
    }

    public function enableTwoFactor(Request $request): RedirectResponse|View
    {
        $request->validate(['code' => ['required', 'string', 'max:10']]);
        $user = $request->user();
        $secret = (string) $request->session()->get('2fa:setup');
        if ($secret === '' || ! $this->tf->verify($user, $secret, (string) $request->input('code'))) {
            return back()->with('error', 'Código inválido. Confira a hora do celular e tente de novo.');
        }
        $codes = $this->tf->generateRecoveryCodes();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => $codes['hashed'], 'two_factor_confirmed_at' => now()])->save();
        $request->session()->forget('2fa:setup');
        $this->audit->log('seguranca.2fa_ativar', $user);

        return view('security.recovery-codes', ['codes' => $codes['plain']]);
    }

    public function disableTwoFactor(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password'], 'code' => ['required', 'string', 'max:20']],
            ['password.current_password' => 'Senha incorreta.', 'code.required' => 'Informe o código do aplicativo autenticador (ou um código de recuperação).']);
        $user = $request->user();
        if ($user->requiresTwoFactor()) {
            return back()->with('error', 'Para administradores da plataforma a verificação em duas etapas é obrigatória.');
        }
        // confirmação segura: além da senha, um código válido do aplicativo ou um código de recuperação
        $code = trim((string) $request->input('code'));
        $valid = preg_match('/^\d{6}$/', preg_replace('/\s+/', '', $code) ?? '')
            ? $this->tf->verify($user, (string) $user->two_factor_secret, $code)
            : $this->tf->useRecoveryCode($user, $code);
        if (! $valid) {
            $this->audit->log('seguranca.2fa_desativar_recusado', $user);

            return back()->withErrors(['code' => 'Código inválido ou expirado. Use o código atual do aplicativo autenticador.']);
        }
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $this->audit->log('seguranca.2fa_desativar', $user);

        return back()->with('ok', 'Verificação em duas etapas desativada.');
    }

    public function password(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password'], 'password' => ['required', 'confirmed', PasswordRules::default()]]);
        $user = $request->user();
        if (Hash::check($request->input('password'), $user->password)) {
            return back()->with('error', 'A nova senha precisa ser diferente da atual.');
        }
        $user->forceFill(['password' => $request->input('password')])->save();
        $request->session()->regenerate();
        $this->audit->log('seguranca.senha_alterar', $user);

        return back()->with('ok', 'Senha alterada.');
    }
}
