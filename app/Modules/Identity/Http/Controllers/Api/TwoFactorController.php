<?php

namespace App\Modules\Identity\Http\Controllers\Api;

use App\Core\Audit\AuditLogger;
use App\Core\Security\TwoFactorService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Gestão do 2FA do próprio usuário (API e web compartilham este fluxo). */
class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly AuditLogger $audit,
    ) {}

    /** Gera um novo segredo (pendente de confirmação). */
    public function setup(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password:'.$this->guard($request)]]);
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return response()->json(['message' => '2FA já está ativo.'], 409);
        }

        $secret = $this->twoFactor->generateSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null])->save();
        $url = $this->twoFactor->otpauthUrl($user, $secret);

        return response()->json([
            'secret' => $secret,
            'otpauth_url' => $url,
            'qr_svg' => $this->twoFactor->qrCodeSvg($url),
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        if (! $user->two_factor_secret || $user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Nenhuma configuração de 2FA pendente.'], 409);
        }

        if (! $this->twoFactor->verify($user, $user->two_factor_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'Código inválido.']);
        }

        $codes = $this->twoFactor->generateRecoveryCodes();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes['hashed']])->save();
        $this->audit->record('auth.2fa.enabled', $user);

        return response()->json([
            'message' => 'Autenticação em dois fatores ativada.',
            'recovery_codes' => $codes['plain'],
        ]);
    }

    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'current_password:'.$this->guard($request)],
            'code' => ['required', 'string'],
        ]);
        $user = $request->user();

        if ($user->is_super_admin && config('aivexa.security.super_admin_requires_2fa')) {
            return response()->json(['message' => '2FA é obrigatório para administradores da plataforma.'], 409);
        }

        if (! $user->hasTwoFactorEnabled() || ! $this->twoFactor->verify($user, $user->two_factor_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'Código inválido.']);
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();
        $this->audit->record('auth.2fa.disabled', $user);

        return response()->json(['message' => 'Autenticação em dois fatores desativada.']);
    }

    private function guard(Request $request): string
    {
        return $request->is('api/*') ? 'sanctum' : 'web';
    }
}
