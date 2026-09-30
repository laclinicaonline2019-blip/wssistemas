<?php

namespace App\Modules\Identity\Http\Controllers\Web;

use App\Core\Audit\AuditLogger;
use App\Core\Security\LoginService;
use App\Core\Security\TwoFactorService;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    private const PENDING_KEY = 'auth.2fa.pending';

    public function __construct(
        private readonly LoginService $login,
        private readonly TwoFactorService $twoFactor,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $result = $this->login->attempt($data['email'], $data['password'], (string) $request->ip());

        if (! $result->successful()) {
            throw ValidationException::withMessages(['email' => $result->message()]);
        }

        $user = $result->user;

        if ($user->hasTwoFactorEnabled()) {
            $request->session()->regenerate();
            $request->session()->put(self::PENDING_KEY, ['id' => $user->id, 'at' => now()->timestamp]);

            return redirect()->route('two-factor.challenge');
        }

        return $this->complete($request, $user);
    }

    public function showChallenge(Request $request): View|RedirectResponse
    {
        if (! $this->pendingUser($request)) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function challenge(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:20'],
            'recovery_code' => ['nullable', 'string', 'max:20'],
        ]);

        $user = $this->pendingUser($request);

        if (! $user) {
            return redirect()->route('login')->withErrors(['email' => 'Sessão de login expirada. Entre novamente.']);
        }

        $valid = ! empty($data['code'])
            ? $this->twoFactor->verify($user, $user->two_factor_secret, $data['code'])
            : (! empty($data['recovery_code']) && $this->twoFactor->useRecoveryCode($user, $data['recovery_code']));

        if (! $valid) {
            $this->login->registerFailure($user, 'invalid_2fa');

            if ($user->refresh()->isLocked()) {
                $request->session()->forget(self::PENDING_KEY);

                return redirect()->route('login')->withErrors(['email' => 'Conta temporariamente bloqueada por tentativas inválidas.']);
            }

            throw ValidationException::withMessages(['code' => 'Código inválido.']);
        }

        if (! empty($data['recovery_code'])) {
            $this->audit->record('auth.2fa.recovery_code_used', $user, userId: $user->id);
        }

        $request->session()->forget(self::PENDING_KEY);

        return $this->complete($request, $user);
    }

    public function logout(Request $request): RedirectResponse
    {
        $user = $request->user();
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user) {
            $this->audit->record('auth.logout', $user, metadata: ['channel' => 'web'], userId: $user->id, companyId: $user->company_id);
        }

        return redirect()->route('login');
    }

    private function complete(Request $request, User $user): RedirectResponse
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $this->login->completed($user, (string) $request->ip(), 'web');

        return redirect()->intended($user->is_super_admin ? route('platform.dashboard') : route('home'));
    }

    /** Usuário com senha validada aguardando o 2º fator (máx. 5 minutos). */
    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get(self::PENDING_KEY);

        if (! $pending || now()->timestamp - $pending['at'] > 300) {
            return null;
        }

        $user = User::find($pending['id']);

        return $user && $user->isActive() && ! $user->isLocked() ? $user : null;
    }
}
