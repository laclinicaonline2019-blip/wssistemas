<?php

namespace App\Modules\Documents\Signature;

use App\Modules\Documents\Models\MedicalDocument;

/**
 * Assinatura digital de documentos médicos (arquitetura para ICP-Brasil).
 *
 * Receita/atestado digitais só têm validade jurídica com assinatura qualificada
 * ICP-Brasil (MP 2.200-2/2001, Lei 14.063/2020, Res. CFM 2.299/2021). A integração
 * prevista é com certificados em nuvem (ex.: BirdID, VIDaaS, SafeID, Certillion)
 * gerando PDF assinado no padrão PAdES. Enquanto nenhum provedor estiver
 * configurado, os documentos são impressos e assinados de próprio punho —
 * nenhuma assinatura é simulada.
 */
interface DocumentSigner
{
    /** Identificador do provedor (ex.: "birdid"). */
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * Assina o PDF do documento e devolve o PDF assinado + referência do provedor.
     *
     * @return array{pdf: string, reference: string}
     */
    public function sign(MedicalDocument $document, string $pdf): array;
}
