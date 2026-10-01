<?php

namespace App\Modules\Documents\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Models\PatientFile;
use App\Modules\Documents\Services\PatientFileService;
use App\Modules\Patients\Models\Patient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Anexos do paciente: envio (documento.anexar), visualização/download (documento.visualizar ou prontuário). */
class PatientFileController extends Controller
{
    public function __construct(private readonly PatientFileService $files) {}

    public function store(Request $request, Patient $patient): RedirectResponse
    {
        abort_if($patient->isAnonymized(), 403);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.(int) config('aivexa.uploads.max_kb', 10240), 'mimetypes:application/pdf,image/jpeg,image/png,image/webp'],
            'category' => ['required', Rule::in(array_keys(PatientFile::CATEGORIES))],
            'title' => ['nullable', 'string', 'max:150'],
            'encounter_id' => ['nullable', 'string', 'size:26'],
        ], [], ['file' => 'arquivo', 'category' => 'categoria']);

        $this->files->store($request->user(), $patient, $request->file('file'), $data);

        return back()->with('success', 'Arquivo anexado ao paciente.');
    }

    public function download(Request $request, PatientFile $file): StreamedResponse
    {
        $this->files->recordDownload($file);
        $disk = Storage::disk($file->disk);
        abort_unless($disk->exists($file->path), 404, 'Arquivo não encontrado no armazenamento.');

        $name = preg_replace('/[^\w.\- ]+/u', '_', $file->original_name);
        $inline = $request->boolean('inline') && ($file->isImage() || $file->mime === 'application/pdf');

        return $disk->response($file->path, $name, [
            'Content-Type' => $file->mime,
            'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ], $inline ? 'inline' : 'attachment');
    }

    public function archive(PatientFile $file): RedirectResponse
    {
        $file->update(['status' => $file->status === 'active' ? 'archived' : 'active']);

        return back()->with('success', $file->status === 'archived' ? 'Arquivo arquivado (continua guardado e pode ser restaurado).' : 'Arquivo restaurado.');
    }
}
