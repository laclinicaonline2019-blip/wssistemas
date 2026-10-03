<?php

namespace App\Core\Security;

use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\TenantContext;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Verificação de credenciais com rate limit, bloqueio progressivo,
 * mensagens neutras (anti-enumeração) e auditoria de todas as tentativas.
 */
class LoginService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function attempt(string $email, string $password, string $ip): LoginResult
    {
        $email = mb_strtolower(trim($email));
        $throttleKey = 'login:'.sha1($email.'|'.$ip);
        $maxPerMinute = config('aivexa.security.login_rate_per_minute');

        if (RateLimiter::tooManyAttempts($throttleKey, $maxPerMinute)) {
            $retry = RateLimiter::availableIn($throttleKey);
            $this->audit->record('auth.login.throttled', result: 'denied', metadata: ['email' => $email]);

            return LoginResult::fail(LoginResult::THROTTLED, $retry);
        }

        RateLimiter::hit($throttleKey, 60);

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            // Custo equivalente ao de uma verificação real (mitiga timing attack).
            Hash::make($password);
            $this->audit->record('auth.login.failed', result: 'failure', metadata: ['email' => $email, 'reason' => 'unknown_user']);

            return LoginResult::fail(LoginResult::INVALID);
        }

        if ($user->isLocked()) {
            $this->audit->record('auth.login.failed', $user, result: 'denied', metadata: ['reason' => 'locked'], userId: $user->id);

            return LoginResult::fail(LoginResult::LOCKED);
        }

        if (! Hash::check($password, $user->password)) {
            $this->registerFailure($user);

            return LoginResult::fail($user->isLocked() ? LoginResult::LOCKED : LoginResult::INVALID);
        }

        if (! $user->isActive()) {
            $this->audit->record('auth.login.failed', $user, result: 'denied', metadata: ['reason' => 'user_'.$user->status], userId: $user->id);

            return LoginResult::fail(LoginResult::BLOCKED);
        }

        $billingOnly = ! $user->is_super_admin && $user->company && ! $user->company->isOperational() && $user->company->billingLocked()
            && app(TenantContext::class)->runFor($user->company_id, fn () => $user->hasPermission('assinatura.gerenciar'));
        if (! $user->is_super_admin && ! $user->company?->isOperational() && ! $billingOnly) {
            $this->audit->record('auth.login.failed', $user, result: 'denied', metadata: ['reason' => 'company_inactive'], userId: $user->id);

            return LoginResult::fail(LoginResult::COMPANY_INACTIVE);
        }

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $password]);
        }

        RateLimiter::clear($throttleKey);

        return LoginResult::ok($user);
    }

    /** Chamado quando o login está totalmente concluído (inclusive 2FA). */
    public function completed(User $user, string $ip, string $channel): void
    {
        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->save();

        $this->audit->record('auth.login', $user, metadata: ['channel' => $channel], userId: $user->id);
    }

    public function registerFailure(User $user, string $reason = 'invalid_password'): void
    {
        $attempts = $user->failed_login_attempts + 1;
        $data = ['failed_login_attempts' => $attempts];

        if ($attempts >= config('aivexa.security.lockout_threshold')) {
            $data['locked_until'] = now()->addMinutes(config('aivexa.security.lockout_minutes'));
            $data['failed_login_attempts'] = 0;
        }

        $user->forceFill($data)->save();

        $this->audit->record(
            isset($data['locked_until']) ? 'auth.account.locked' : 'auth.login.failed',
            $user,
            result: 'failure',
            metadata: ['reason' => $reason, 'attempts' => $attempts],
            userId: $user->id,
        );
    }
}
