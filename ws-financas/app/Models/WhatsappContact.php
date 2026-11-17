<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Telefone autorizado a lançar/consultar pelo WhatsApp em nome de um cliente. */
class WhatsappContact extends Model
{
    use BelongsToAccount, HasUlids;

    protected ?string $companyColumn = null;

    protected $fillable = ['user_id', 'label', 'phone', 'phone_hash', 'phone_last4', 'default_company_id', 'can_write', 'reminders_opt_in', 'active', 'verified_at', 'verify_code_hash', 'verify_expires_at', 'verify_attempts'];

    protected $hidden = ['verify_code_hash'];

    public const VERIFY_MAX_ATTEMPTS = 5;

    protected function casts(): array
    {
        return ['phone' => 'encrypted', 'can_write' => 'boolean', 'reminders_opt_in' => 'boolean', 'active' => 'boolean', 'verified_at' => 'datetime', 'verify_expires_at' => 'datetime'];
    }

    /** Normaliza para E.164 brasileiro sem "+" (55 + DDD + número). */
    public static function normalize(string $phone): string
    {
        $d = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($d) >= 10 && strlen($d) <= 11) {
            $d = '55'.$d;
        }

        return $d;
    }

    /**
     * Hash de busca. A Meta pode entregar celulares brasileiros sem o 9º dígito; o hash usa a forma
     * sem o 9 para que "55 11 9xxxx-xxxx" e "55 11 xxxx-xxxx" sejam o mesmo contato.
     */
    public static function hashPhone(string $phone): string
    {
        $d = static::normalize($phone);
        if (strlen($d) === 13 && str_starts_with($d, '55') && $d[4] === '9') {
            $d = substr($d, 0, 4).substr($d, 5);
        }

        return hash_hmac('sha256', $d, (string) config('app.key'));
    }

    public function verified(): bool
    {
        return $this->verified_at !== null;
    }

    /** Gera um novo código de validação (6 dígitos, 24 h). Só o hash fica no banco; o código é mostrado uma vez ao administrador. */
    public function issueCode(): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->forceFill(['verify_code_hash' => $this->codeHash($code), 'verify_expires_at' => now()->addDay(), 'verify_attempts' => 0])->save();

        return $code;
    }

    /** Confere o código enviado pelo próprio celular. @return 'ok'|'invalid'|'expired'|'locked' */
    public function checkCode(string $text): string
    {
        if ($this->verify_attempts >= self::VERIFY_MAX_ATTEMPTS) {
            return 'locked';
        }
        if (! $this->verify_code_hash || ! $this->verify_expires_at || $this->verify_expires_at->isPast()) {
            return 'expired';
        }
        preg_match_all('/(?<!\d)\d{3}\s?-?\s?\d{3}(?!\d)/', $text, $m);
        foreach ($m[0] as $candidate) {
            if (hash_equals($this->verify_code_hash, $this->codeHash(preg_replace('/\D/', '', $candidate)))) {
                $this->forceFill(['verified_at' => now(), 'verify_code_hash' => null, 'verify_expires_at' => null, 'verify_attempts' => 0])->save();

                return 'ok';
            }
        }
        $this->forceFill(['verify_attempts' => $this->verify_attempts + 1])->save();

        return $this->verify_attempts >= self::VERIFY_MAX_ATTEMPTS ? 'locked' : 'invalid';
    }

    private function codeHash(string $code): string
    {
        return hash_hmac('sha256', $this->getKey().'|'.$code, (string) config('app.key'));
    }

    public function defaultCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'default_company_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
