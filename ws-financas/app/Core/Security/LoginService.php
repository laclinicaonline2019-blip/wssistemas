<?php

namespace App\Core\Security;

use App\Core\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Login com bloqueio progressivo e mensagens neutras (não revela se o e-mail existe).
 * O custo do hash é equalizado para e-mails inexistentes (evita enumeração por tempo).
 */
class LoginService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{ok: bool, user?: User, message?: string} */
    public function attempt(string $email, string $password): array
    {
        $user = User::query()->where('email', mb_strtolower(trim($email)))->first();
        $neutral = 'E-mail ou senha inválidos.';

        if (! $user) {
            Hash::check($password, '$argon2id$v=19$m=1024,t=1,p=1$c29tZXNhbHQ$Zm9vYmFyYmF6');

            return ['ok' => false, 'message' => $neutral];
        }

        if ($user->locked_until && $user->locked_until->isFuture()) {
            return ['ok' => false, 'message' => 'Acesso bloqueado temporariamente por excesso de tentativas. Tente novamente mais tarde ou recupere a senha.'];
        }

        if (! Hash::check($password, $user->password)) {
            $fails = $user->failed_logins + 1;
            $lock = $fails >= (int) config('wsf.security.max_login_attempts')
                ? now()->addMinutes((int) config('wsf.security.lockout_minutes') * max(1, $fails - (int) config('wsf.security.max_login_attempts') + 1))
                : null;
            $user->forceFill(['failed_logins' => $fails, 'locked_until' => $lock])->save();
            $this->audit->log('auth.falha', $user, ['bloqueado' => (bool) $lock], userId: $user->id);

            return ['ok' => false, 'message' => $neutral];
        }

        if ($user->status !== 'active') {
            return ['ok' => false, 'message' => 'Usuário desativado. Fale com o administrador.'];
        }

        if (Hash::needsRehash($user->password)) {
            $user->password = $password;
        }
        $user->forceFill(['failed_logins' => 0, 'locked_until' => null])->save();

        return ['ok' => true, 'user' => $user];
    }
}
