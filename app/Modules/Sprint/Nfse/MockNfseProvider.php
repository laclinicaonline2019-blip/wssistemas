<?php

namespace App\Modules\Sprint\Nfse;

use App\Modules\Payments\Models\PaymentGateway;
use App\Modules\Sprint\Models\SprintInvoice;
use App\Modules\Sprint\Models\SprintSetting;
use Illuminate\Support\Str;

/** NFS-e MOCK (demonstração/testes): autoriza na hora, sem valor fiscal — identificada como MOCK. */
class MockNfseProvider implements NfseProvider
{
    public function issue(PaymentGateway $gateway, SprintInvoice $invoice, SprintSetting $settings, ?string $customerId): NfseResult
    {
        return new NfseResult('mock_inv_'.Str::lower((string) Str::ulid()), 'authorized', 'MOCK-'.random_int(1000, 999999), strtoupper(Str::random(8)));
    }

    public function fetch(PaymentGateway $gateway, string $providerInvoiceId): NfseResult
    {
        return new NfseResult($providerInvoiceId, 'authorized');
    }

    public function cancel(PaymentGateway $gateway, string $providerInvoiceId): NfseResult
    {
        return new NfseResult($providerInvoiceId, 'cancelled');
    }
}
