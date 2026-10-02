<?php

namespace App\Modules\Payments\Providers;

use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentGateway;

/**
 * Gateway em que o paciente digita o cartão numa página do sistema, mas os dados
 * vão do NAVEGADOR direto para o gateway (tokenização). O servidor só recebe um
 * token temporário — nunca número, validade ou CVV.
 */
interface CardTokenProvider
{
    /** Dados para o script de tokenização no navegador: access_token, environment, script_url. */
    public function tokenizationConfig(PaymentGateway $gateway): array;

    /**
     * Autoriza/captura a venda com o token do cartão (e o split, se houver).
     *
     * @param  array{subordinate_id: string, amount_cents: int}|null  $split
     * @return array{approved: bool, payment_id: ?string, message: string}
     */
    public function authorizeCard(PaymentGateway $gateway, PaymentCharge $charge, string $paymentToken, string $brand, int $installments, string $holderName, ?array $split): array;
}
