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

    protected $fillable = ['user_id', 'label', 'phone', 'phone_hash', 'phone_last4', 'default_company_id', 'can_write', 'reminders_opt_in', 'active'];

    protected function casts(): array
    {
        return ['phone' => 'encrypted', 'can_write' => 'boolean', 'reminders_opt_in' => 'boolean', 'active' => 'boolean'];
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

    public function defaultCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'default_company_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
