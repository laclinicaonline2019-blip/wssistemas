<?php

namespace App\Modules\Ai\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Modules\Ai\Models\AiMedia;
use App\Modules\Ai\Services\Media\MediaPipeline;
use App\Modules\Documents\Models\PatientFile;
use App\Modules\Patients\Models\Patient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Documentos, fotos e áudios lidos pela IA — conferência humana obrigatória (ia.conversas). */
class AiMediaWebController extends Controller
{
    public function index(Request $request): View
    {
        $f = $request->validate(['review' => ['nullable', Rule::in(array_keys(AiMedia::REVIEW))], 'type' => ['nullable', Rule::in(array_keys(AiMedia::DOC_TYPES))]]);
        $review = $f['review'] ?? 'pending';

        return view('ai.media-index', [
            'items' => AiMedia::query()->with(['patient:id,name,social_name,record_number', 'thread:id,phone,contact_name'])
                ->where('review_status', $review)->where('kind', '!=', 'audio')
                ->when($f['type'] ?? null, fn ($q, $t) => $q->where('doc_type', $t))
                ->latest()->paginate(30)->withQueryString(),
            'review' => $review, 'type' => $f['type'] ?? null,
            'pendingCount' => AiMedia::query()->where('review_status', 'pending')->where('kind', '!=', 'audio')->count(),
        ]);
    }

    public function show(AiMedia $media): View
    {
        return view('ai.media-show', ['media' => $media->load(['patient', 'thread.patient', 'reviewer:id,name', 'patientFile:id,title']), 'categories' => PatientFile::CATEGORIES]);
    }

    /** Arquivo original (privado, sem cache, auditado). */
    public function file(Request $request, AiMedia $media, AuditLogger $audit): StreamedResponse
    {
        $disk = Storage::disk($media->disk);
        abort_unless($media->path && $disk->exists($media->path), 404, 'Arquivo não disponível.');
        $audit->record('ai.media_downloaded', $media, metadata: ['patient_id' => $media->patient_id]);
        $inline = $request->boolean('inline') && ($media->isImage() || $media->mime === 'application/pdf' || $media->kind === 'audio');

        return $disk->response($media->path, 'whatsapp-'.$media->id.'.'.pathinfo($media->path, PATHINFO_EXTENSION), [
            'Content-Type' => $media->mime, 'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox",
        ], $inline ? 'inline' : 'attachment');
    }

    public function verify(Request $request, AiMedia $media, MediaPipeline $pipeline): RedirectResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);
        $pipeline->verify($request->user(), $media, $data['notes'] ?? null);

        return back()->with('success', 'Documento conferido.');
    }

    public function discard(Request $request, AiMedia $media, MediaPipeline $pipeline): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], [], ['reason' => 'motivo']);
        $pipeline->discard($request->user(), $media, $data['reason']);

        return back()->with('success', 'Documento descartado da conferência (o arquivo continua guardado).');
    }

    public function attach(Request $request, AiMedia $media, MediaPipeline $pipeline): RedirectResponse
    {
        $data = $request->validate([
            'record_number' => [Rule::requiredIf(! $media->patient_id), 'nullable', 'integer', 'min:1'],
            'category' => ['required', Rule::in(array_keys(PatientFile::CATEGORIES))], 'title' => ['required', 'string', 'max:150'],
        ], [], ['record_number' => 'nº do prontuário', 'title' => 'título']);
        $patient = ! empty($data['record_number'])
            ? Patient::query()->where('record_number', $data['record_number'])->first()
            : Patient::query()->find($media->patient_id);
        if (! $patient) {
            return back()->withErrors(['record_number' => 'Paciente não encontrado com este nº de prontuário.'])->withInput();
        }
        $pipeline->attach($request->user(), $media, $patient, $data['category'], $data['title']);

        return back()->with('success', 'Arquivo anexado à ficha de '.$patient->displayName().'.');
    }

    /** "Ler com IA" num arquivo já anexado ao paciente. */
    public function readFile(Request $request, PatientFile $file, MediaPipeline $pipeline): RedirectResponse
    {
        abort_unless($file->status === 'active', 404);
        $media = $pipeline->readPatientFile($request->user(), $file);

        return redirect()->route('ai.media.show', $media)->with($media->status === 'processed' ? 'success' : 'error',
            $media->status === 'processed' ? 'Documento lido pela IA. Confira os dados antes de usar.' : 'Não foi possível ler: '.$media->error);
    }
}
