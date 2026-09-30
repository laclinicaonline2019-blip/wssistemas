<?php

namespace App\Modules\Identity\Http\Controllers\Web;

use App\Core\Audit\AuditLogger;
use App\Core\Security\PasswordRules;
use App\Core\Security\TwoFactorService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Minha conta: senha e autenticação em dois fatores. */
class AccountController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly AuditLogger $audit,
    ) {}

    public function security(Request $request): View
    {
        $user = $request->user();
        $qr = null;

        if ($user->two_factor_secret && ! $user->hasTwoFactorEnabled()) {
            $qr = $this->twoFactor->qrCodeSvg($this->twoFactor->otpauthUrl($user, $user->two_factor_secret));
        }

        return view('account.security', [
            'user' => $user,
            'qrSvg' => $qr,
            'recoveryCodes' => $request->session()->get('recovery_codes'),
        ]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', 'different:current_password', PasswordRules::default()],
        ]);

        $user = $request->user();
        $user->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();
        $user->tokens()->delete();
        Auth::logoutOtherDevices($data['password']);
        $this->audit->record('auth.password_changed', $user);

        return back()->with('success', 'Senha alterada. Outras sessões e tokens foram encerrados.');
    }

    public function enableTwoFactor(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password:web']]);
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            $user->forceFill(['two_factor_secret' => $this->twoFactor->generateSecret(), 'two_factor_confirmed_at' => null])->save();
        }

        return back();
    }

    public function confirmTwoFactor(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        if (! $user->two_factor_secret || $user->hasTwoFactorEnabled()
            || ! $this->twoFactor->verify($user, $user->two_factor_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'Código inválido.']);
        }

        $codes = $this->twoFactor->generateRecoveryCodes();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes['hashed']])->save();
        $this->audit->record('auth.2fa.enabled', $user);

        return back()->with('success', 'Autenticação em dois fatores ativada.')->with('recovery_codes', $codes['plain']);
    }

    public function disableTwoFactor(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'current_password:web'],
            'code' => ['required', 'string'],
        ]);
        $user = $request->user();

        if ($user->is_super_admin && config('aivexa.security.super_admin_requires_2fa')) {
            return back()->with('error', '2FA é obrigatório para administradores da plataforma.');
        }

        if (! $user->hasTwoFactorEnabled() || ! $this->twoFactor->verify($user, $user->two_factor_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'Código inválido.']);
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();
        $this->audit->record('auth.2fa.disabled', $user);

        return back()->with('success', 'Autenticação em dois fatores desativada.');
    }
}
