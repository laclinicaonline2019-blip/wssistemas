<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Modo de demonstração/testes: não envia nada, só registra. O webhook aceita o formato simples
 * {"phone": "...", "text": "...", "name": "...", "image_base64": "...", "mime": "image/jpeg"}.
 */
class MockGateway implements WhatsAppGateway
{
    /** @var array<int, array{phone:string, text:string}> */
    public static array $sent = [];

    public function sendText(string $phone, string $text): ?string
    {
        self::$sent[] = ['phone' => $phone, 'text' => $text];
        Log::channel('single')->info('[WhatsApp MOCK] → '.$phone.': '.$text);

        return 'mock-'.bin2hex(random_bytes(6));
    }

    public function sendImage(string $phone, string $bytes, string $mime, ?string $caption = null): ?string
    {
        return $this->sendText($phone, '[imagem] '.(string) $caption);
    }

    public function parse(Request $request): array
    {
        $phone = (string) $request->input('phone');
        if ($phone === '') {
            return [];
        }
        $bytes = $request->filled('image_base64') ? base64_decode((string) $request->input('image_base64'), true) : null;

        return [new InboundMessage(
            phone: $phone,
            type: $bytes ? 'image' : 'text',
            text: $request->input('text'),
            senderName: $request->input('name'),
            providerId: $request->input('id'),
            mediaBytes: $bytes ?: null,
            mime: $bytes ? (string) $request->input('mime', 'image/jpeg') : null,
        )];
    }
}
