<?php

namespace App\Core\Security;

use App\Modules\Identity\Models\User;

final class LoginResult
{
    public const OK = 'ok';

    public const INVALID = 'invalid';

    public const THROTTLED = 'throttled';

    public const LOCKED = 'locked';

    public const BLOCKED = 'blocked';

    public const COMPANY_INACTIVE = 'company_inactive';

    private function __construct(
        public readonly string $status,
        public readonly ?User $user = null,
        public readonly int $retryAfterSeconds = 0,
    ) {}

    public static function ok(User $user): self
    {
        return new self(self::OK, $user);
    }

    public static function fail(string $status, int $retryAfter = 0): self
    {
        return new self($status, null, $retryAfter);
    }

    public function successful(): bool
    {
        return $this->status === self::OK;
    }

    /** Mensagem neutra: não revela se o e-mail existe. */
    public function message(): string
    {
        return match ($this->status) {
            self::THROTTLED => "Muitas tentativas. Aguarde {$this->retryAfterSeconds} segundos.",
            self::LOCKED => 'Conta temporariamente bloqueada por tentativas inválidas. Tente mais tarde ou contate o administrador.',
            self::BLOCKED => 'Credenciais inválidas ou acesso não permitido.',
            self::COMPANY_INACTIVE => 'O acesso da sua clínica está suspenso. Contate o suporte.',
            default => 'Credenciais inválidas ou acesso não permitido.',
        };
    }
}
