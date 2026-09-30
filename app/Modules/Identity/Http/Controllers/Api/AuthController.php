<?php

namespace App\Modules\Identity\Http\Controllers\Api;

use App\Core\Access\PermissionService;
use App\Core\Audit\AuditLogger;
use App\Core\Security\LoginService;
use App\Core\Security\TwoFactorService;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Services\AccessGuard;
use App\Modules\Organization\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly LoginService $login,
        private readonly TwoFactorService $twoFactor,
        private readonly AuditLogger $audit,
    ) {}

    /** Emite token de API (Sanctum). 2FA exigido quando habilitado. */
    public function token(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:20'],
            'recovery_code' => ['nullable', 'string', 'max:20'],
        ]);

        $result = $this->login->attempt($data['email'], $data['password'], (string) $request->ip());

        if (! $result->successful()) {
            return response()->json(['message' => $result->message(), 'code' => 'auth_'.$result->status], $result->status === 'throttled' ? 429 : 401);
        }

        $user = $result->user;

        if ($user->hasTwoFactorEnabled()) {
            if (empty($data['code']) && empty($data['recovery_code'])) {
                return response()->json(['message' => 'Informe o código de autenticação em dois fatores.', 'code' => 'two_factor_required'], 401);
            }

            $valid = ! empty($data['code'])
                ? $this->twoFactor->verify($user, $user->two_factor_secret, $data['code'])
                : $this->twoFactor->useRecoveryCode($user, $data['recovery_code']);

            if (! $valid) {
                $this->login->registerFailure($user, 'invalid_2fa');

                return response()->json(['message' => 'Código de autenticação inválido.', 'code' => 'two_factor_invalid'], 401);
            }
        }

        $ttl = config('aivexa.security.api_token_ttl_minutes');
        $token = $user->createToken($data['device_name'], ['*'], now()->addMinutes($ttl));
        $this->login->completed($user, (string) $request->ip(), 'api');

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => new UserResource($user),
        ], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        $this->audit->record('auth.logout', $request->user(), metadata: ['channel' => 'api']);

        return response()->json(['message' => 'Sessão encerrada.']);
    }

    public function me(Request $request, PermissionService $permissions): JsonResponse
    {
        $user = $request->user();
        $allowed = $user->is_super_admin ? [] : $permissions->allowedBranchIds($user);

        return response()->json([
            'user' => new UserResource($user),
            'company' => $user->company?->only(['id', 'trade_name', 'slug', 'status']),
            'branches' => $user->is_super_admin ? [] : Branch::query()->withoutGlobalScopes()
                ->where('company_id', $user->company_id)->active()->accessible($allowed)
                ->orderByDesc('is_headquarters')->orderBy('name')->get(['id', 'name', 'code', 'is_headquarters']),
            'permissions' => [
                'company_wide' => $permissions->effective($user, AccessGuard::COMPANY_WIDE),
            ],
        ]);
    }
}
