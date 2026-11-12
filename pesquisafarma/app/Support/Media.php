<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Segurança de mídia: o tipo do arquivo é detectado pelo CONTEÚDO (nunca pelo que o remetente diz),
 * só tipos permitidos são aceitos e downloads de provedores não podem apontar para a rede interna.
 */
class Media
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** Tipos que podem ser exibidos no navegador da equipe. */
    public const INLINE = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];

    /** Tipos aceitos do WhatsApp (áudio é guardado, mas só para download). */
    public const ACCEPTED = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf', 'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/aac', 'video/mp4'];

    /** Tipo real pelo conteúdo, ou null se não for um tipo aceito. */
    public static function detect(?string $bytes): ?string
    {
        if ($bytes === null || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return null;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        if ($mime === 'application/ogg') {
            $mime = 'audio/ogg';
        }

        return in_array($mime, self::ACCEPTED, true) ? $mime : null;
    }

    public static function extension(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'application/pdf' => 'pdf',
            'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4', 'audio/aac' => 'm4a', 'video/mp4' => 'mp4', default => 'jpg',
        };
    }

    /** Baixa mídia do provedor só por HTTPS e para endereços públicos (bloqueia SSRF), com limite de tamanho. */
    public static function download(string $url): string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new RuntimeException('URL de mídia inválida.');
        }
        foreach (gethostbynamel($parts['host']) ?: [] as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('Endereço de mídia não permitido.');
            }
        }
        $res = Http::timeout(30)->withOptions(['allow_redirects' => false])->get($url);
        if (! $res->successful() || strlen($res->body()) > self::MAX_BYTES) {
            throw new RuntimeException('Falha ao baixar a mídia.');
        }

        return $res->body();
    }
}
