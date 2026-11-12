<?php

namespace App\Services\WhatsApp;

use App\Models\Customer;
use App\Models\Pharmacy;
use App\Models\WaMessage;
use App\Support\Text;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Envio de mensagens com registro de tudo na conversa (quem enviou, quando, qual cotação). */
class WhatsAppService
{
    public function __construct(private readonly WhatsAppGateway $gateway) {}

    public function gateway(): WhatsAppGateway
    {
        return $this->gateway;
    }

    public function toCustomer(Customer $customer, string $text, ?int $quoteId = null): WaMessage
    {
        return $this->send($customer->phone, $text, ['customer_id' => $customer->id, 'quote_id' => $quoteId]);
    }

    public function toPharmacy(Pharmacy $pharmacy, string $text, ?int $quoteId = null): ?WaMessage
    {
        $phone = $pharmacy->whatsapp ?: $pharmacy->phone;

        return $phone ? $this->send($phone, $text, ['pharmacy_id' => $pharmacy->id, 'quote_id' => $quoteId]) : null;
    }

    public function send(string $phone, string $text, array $context = []): WaMessage
    {
        $phone = Text::phone($phone);
        $status = 'ok';
        $id = null;
        try {
            $id = $this->gateway->sendText($phone, $text);
        } catch (Throwable $e) {
            $status = 'falhou';
            Log::warning('Falha no envio WhatsApp para '.$phone.': '.$e->getMessage());
        }

        return WaMessage::create($context + [
            'phone' => $phone, 'direction' => 'out', 'type' => 'text', 'body' => $text,
            'provider_id' => $id, 'status' => $status, 'user_id' => auth()->id(),
        ]);
    }
}
