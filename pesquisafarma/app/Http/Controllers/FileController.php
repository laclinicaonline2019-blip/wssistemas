<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use App\Models\Receipt;
use App\Models\WaMessage;
use App\Support\Media;
use Illuminate\Support\Facades\Storage;

/** Arquivos privados (receitas, fotos, notas) — só para a equipe. */
class FileController extends Controller
{
    public function show(string $type, int $id)
    {
        abort_unless(auth()->user()->isStaff(), 403);
        [$path, $mime] = match ($type) {
            'cotacao' => [($q = Quote::findOrFail($id))->attachment_path, $q->attachment_mime],
            'nota' => [($r = Receipt::findOrFail($id))->file_path, $r->mime],
            'mensagem' => [($m = WaMessage::findOrFail($id))->media_path, $m->mime],
            default => abort(404),
        };
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        $bytes = Storage::disk('local')->get($path);
        // Tipo pelo conteúdo real; só imagem/PDF abrem no navegador, o resto vira download.
        $real = Media::detect($bytes);
        $inline = $real && in_array($real, Media::INLINE, true);

        return response($bytes, 200, [
            'Content-Type' => $inline ? $real : 'application/octet-stream',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="arquivo-'.$id.'.'.Media::extension((string) $real).'"',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, max-age=600', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
