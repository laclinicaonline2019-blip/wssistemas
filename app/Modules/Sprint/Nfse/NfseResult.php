<?php

namespace App\Modules\Sprint\Nfse;

/** Situação da NFS-e segundo o emissor. status: scheduled, authorized, error, cancelling, cancelled */
final class NfseResult
{
    public function __construct(
        public readonly ?string $id,
        public readonly string $status,
        public readonly ?string $number = null,
        public readonly ?string $verificationCode = null,
        public readonly ?string $pdfUrl = null,
        public readonly ?string $xmlUrl = null,
        public readonly ?string $error = null,
    ) {}
}
