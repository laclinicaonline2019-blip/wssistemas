<?php

namespace App\Core\Security;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use Illuminate\Support\Facades\Log;

/**
 * Varredura de arquivos enviados (Fase 17).
 *
 * Sempre: verificações próprias contra os vetores mais comuns em clínicas —
 *  - PDF com JavaScript, ação de abrir programa (/Launch), arquivo embutido ou mídia ativa;
 *  - imagem "poliglota" com código PHP/HTML/script escondido;
 *  - o arquivo de teste antivírus EICAR (para homologar o fluxo).
 * Opcional: ClamAV (clamd) por socket Unix/TCP, protocolo INSTREAM — em VPS. Na hospedagem
 * compartilhada normalmente não há ClamAV: o arquivo fica registrado como "verificações básicas".
 */
class FileScanner
{
    public const CLEAN = 'clean';

    public const BASIC = 'basic';      // só verificações próprias (sem antivírus)

    private const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    /**
     * Lança BusinessRuleViolation se o arquivo for recusado. Retorna "clean" (antivírus ok) ou "basic".
     */
    public function assertSafe(string $bytes, string $mime, string $context = 'upload'): string
    {
        $threat = $this->heuristics($bytes, $mime);
        if ($threat === null && config('aivexa.security.scanner.driver') === 'clamav') {
            $result = $this->clamav($bytes);
            if ($result === null) {
                if (config('aivexa.security.scanner.fail_closed')) {
                    $this->reject('antivírus indisponível', $context, $mime, 'Antivírus indisponível no momento. Tente novamente em alguns minutos.');
                }
                Log::warning('Antivírus indisponível: arquivo aceito só com verificações básicas', ['context' => $context]);

                return self::BASIC;
            }
            $threat = $result === 'OK' ? null : $result;
            if ($threat === null) {
                return self::CLEAN;
            }
        }
        if ($threat !== null) {
            $this->reject($threat, $context, $mime, 'Arquivo recusado por segurança ('.$threat.'). Se for um documento legítimo, salve-o novamente como PDF/imagem simples e envie de novo.');
        }

        return self::BASIC;
    }

    public function heuristics(string $bytes, string $mime): ?string
    {
        if (str_contains($bytes, self::EICAR)) {
            return 'arquivo de teste EICAR';
        }
        if ($mime === 'application/pdf') {
            // Nomes de PDF podem vir com caracteres escapados (#4A = J): normaliza antes de procurar.
            $norm = preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn ($m) => chr(hexdec($m[1])), $bytes);
            foreach (['/JavaScript' => 'PDF com JavaScript', '/JS' => 'PDF com JavaScript', '/Launch' => 'PDF que tenta abrir programa',
                '/EmbeddedFile' => 'PDF com arquivo embutido', '/RichMedia' => 'PDF com mídia ativa'] as $needle => $label) {
                if (preg_match('#'.preg_quote($needle, '#').'(?![A-Za-z])#', $norm)) {
                    return $label;
                }
            }
        }
        if (str_starts_with($mime, 'image/') && preg_match('/<\?php|<script\b|<html\b|<iframe\b/i', $bytes)) {
            return 'imagem com código escondido';
        }

        return null;
    }

    /** "OK", nome da ameaça, ou null se o clamd não respondeu. */
    private function clamav(string $bytes): ?string
    {
        $cfg = config('aivexa.security.scanner');
        $target = $cfg['clamav_host'] ? 'tcp://'.$cfg['clamav_host'].':'.$cfg['clamav_port'] : 'unix://'.$cfg['clamav_socket'];
        $sock = @stream_socket_client($target, $errno, $err, 5);
        if (! $sock) {
            return null;
        }
        stream_set_timeout($sock, 30);
        fwrite($sock, "zINSTREAM\0");
        foreach (str_split($bytes, 8192) ?: [''] as $chunk) {
            fwrite($sock, pack('N', strlen($chunk)).$chunk);
        }
        fwrite($sock, pack('N', 0));
        $reply = trim((string) stream_get_contents($sock), "\0\r\n ");
        fclose($sock);
        if ($reply === '') {
            return null;
        }
        if (str_ends_with($reply, 'OK')) {
            return 'OK';
        }

        return preg_match('/:\s*(.+)\s+FOUND$/', $reply, $m) ? 'vírus '.$m[1] : null;
    }

    private function reject(string $threat, string $context, string $mime, string $message): never
    {
        Log::warning('Arquivo recusado pela varredura', ['threat' => $threat, 'context' => $context, 'mime' => $mime]);
        app(AuditLogger::class)->record('security.file_blocked', null, result: 'denied', metadata: ['threat' => $threat, 'context' => $context, 'mime' => $mime]);

        throw new BusinessRuleViolation($message, 'file_blocked');
    }
}
