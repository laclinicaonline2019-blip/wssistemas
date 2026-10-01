<?php

namespace App\Modules\Documents\Services;

use App\Modules\Documents\Models\MedicalDocument;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;

/** PDF dos documentos (dompdf — PHP puro, funciona em hospedagem compartilhada). */
class DocumentPdf
{
    public function __construct(private readonly DocumentService $documents) {}

    /** @param Collection<int, MedicalDocument> $docs */
    public function render(Collection $docs, string $paper = 'a4'): string
    {
        $docs->each->loadMissing('branch:id,timezone');
        $html = view('documents.print', [
            'docs' => $docs, 'format' => $paper, 'preview' => false, 'pdf' => true, 'thermalWidth' => 80, 'service' => $this->documents,
        ])->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);      // nunca busca recursos externos
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');  // acentuação completa
        $options->set('chroot', public_path());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper($paper, 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    public function filename(MedicalDocument $doc): string
    {
        $slug = ['prescription' => 'receita', 'special_prescription' => 'receita-controle-especial', 'notification_record' => 'registro-notificacao',
            'certificate' => 'atestado', 'exam_request' => 'solicitacao-exames', 'report' => 'documento'][$doc->type] ?? 'documento';

        return $slug.'-'.$doc->issued_at->format('Y').'-'.str_pad((string) $doc->number, 6, '0', STR_PAD_LEFT).'.pdf';
    }
}
