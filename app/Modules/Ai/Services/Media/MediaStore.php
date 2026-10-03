<?php

namespace App\Modules\Ai\Services\Media;

use App\Core\Support\BusinessRuleViolation;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Guarda a mídia recebida em disco PRIVADO (fora da pasta pública), por clínica, com nome
 * aleatório e tipo detectado pelo CONTEÚDO. Limites: imagem 5 MB (limite da IA), PDF 10 MB, áudio 16 MB.
 */
class MediaStore
{
    public const DISK = 'local';

    public const TYPES = [
        'image/jpeg' => ['image', 'jpg', 5], 'image/png' => ['image', 'png', 5], 'image/webp' => ['image', 'webp', 5],
        'application/pdf' => ['document', 'pdf', 10],
        'audio/ogg' => ['audio', 'ogg', 16], 'application/ogg' => ['audio', 'ogg', 16], 'audio/mpeg' => ['audio', 'mp3', 16],
        'audio/mp4' => ['audio', 'm4a', 16], 'audio/x-m4a' => ['audio', 'm4a', 16], 'video/mp4' => ['audio', 'mp4', 16],
        'audio/aac' => ['audio', 'aac', 16], 'audio/wav' => ['audio', 'wav', 16], 'audio/x-wav' => ['audio', 'wav', 16],
        'audio/webm' => ['audio', 'webm', 16], 'video/webm' => ['audio', 'webm', 16], 'audio/amr' => ['audio', 'amr', 16],
    ];

    /** @return array{kind: string, mime: string, ext: string, path: string, size: int, sha256: string} */
    public function put(string $companyId, string $bytes): array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        [$kind, $ext, $maxMb] = self::TYPES[$mime] ?? throw new BusinessRuleViolation("Formato de arquivo não aceito ({$mime}).", 'media_type');
        if (strlen($bytes) > $maxMb * 1048576) {
            throw new BusinessRuleViolation("Arquivo maior que {$maxMb} MB.", 'media_size');
        }

        $path = "companies/{$companyId}/messaging-media/".now()->format('Y/m').'/'.Str::ulid().'.'.$ext;
        Storage::disk(self::DISK)->put($path, $bytes);

        return ['kind' => $kind, 'mime' => $mime, 'ext' => $ext, 'path' => $path, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }

    public function get(string $path): string
    {
        return (string) Storage::disk(self::DISK)->get($path);
    }
}
