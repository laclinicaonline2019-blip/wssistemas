<?php

namespace App\Modules\Documents\Signature;

use App\Modules\Documents\Models\MedicalDocument;
use LogicException;

/** Padrão: sem assinatura digital — documento impresso para assinatura manual. */
class NoSigner implements DocumentSigner
{
    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function sign(MedicalDocument $document, string $pdf): array
    {
        throw new LogicException('Nenhum provedor de assinatura digital ICP-Brasil configurado.');
    }
}
