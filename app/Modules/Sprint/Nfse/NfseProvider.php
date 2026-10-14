<?php

namespace App\Modules\Sprint\Nfse;

use App\Modules\Payments\Models\PaymentGateway;
use App\Modules\Sprint\Models\SprintInvoice;
use App\Modules\Sprint\Models\SprintSetting;

/** Emissor de NFS-e (nota de serviço sobre a parte da clínica). */
interface NfseProvider
{
    /** Agenda/emite a nota. Devolve o ID no emissor e a situação inicial. */
    public function issue(PaymentGateway $gateway, SprintInvoice $invoice, SprintSetting $settings, ?string $customerId): NfseResult;

    public function fetch(PaymentGateway $gateway, string $providerInvoiceId): NfseResult;

    public function cancel(PaymentGateway $gateway, string $providerInvoiceId): NfseResult;
}
