<?php

namespace App\Core\Security;

use App\Modules\Identity\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/** TOTP (RFC 6238) + códigos de recuperação. */
class TwoFactorService
{
    public function __construct(private readonly Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(config('aivexa.brand.name'), $user->email, $secret);
    }

    public function qrCodeSvg(string $url): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd));

        return $writer->writeString($url);
    }

    /**
     * Valida um código TOTP, com proteção contra reutilização (replay):
     * o mesmo intervalo de tempo não é aceito duas vezes para o usuário.
     */
    public function verify(User $user, string $secret, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== 6) {
            return false;
        }

        $key = '2fa:last-ts:'.$user->getKey();
        $timestamp = $this->google2fa->verifyKeyNewer($secret, $code, Cache::get($key), 1);

        if ($timestamp === false) {
            return false;
        }

        Cache::put($key, $timestamp === true ? $this->google2fa->getTimestamp() : $timestamp, now()->addMinutes(5));

        return true;
    }

    /** @return array{plain: list<string>, hashed: list<string>} */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $plain = [];
        for ($i = 0; $i < $count; $i++) {
            $plain[] = Str::upper(Str::random(5).'-'.Str::random(5));
        }

        return ['plain' => $plain, 'hashed' => array_map($this->hashRecoveryCode(...), $plain)];
    }

    /** Consome um código de recuperação (uso único). */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];
        $hash = $this->hashRecoveryCode(Str::upper(trim($code)));

        foreach ($codes as $i => $stored) {
            if (hash_equals($stored, $hash)) {
                unset($codes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    private function hashRecoveryCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
