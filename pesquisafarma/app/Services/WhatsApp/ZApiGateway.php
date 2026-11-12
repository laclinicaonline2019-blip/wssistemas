<?php

namespace App\Services\WhatsApp;

use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Z-API (WhatsApp não oficial). */
class ZApiGateway implements WhatsAppGateway
{
    private function base(): string
    {
        return 'https://api.z-api.io/instances/'.config('pesquisafarma.zapi.instance').'/token/'.config('pesquisafarma.zapi.token');
    }

    private function post(string $path, array $body): ?string
    {
        $res = Http::timeout(20)->withHeaders(['Client-Token' => (string) config('pesquisafarma.zapi.client_token')])->post($this->base().$path, $body);
        if (! $res->successful()) {
            throw new RuntimeException('Z-API recusou o envio ('.$res->status().').');
        }

        return $res->json('messageId') ?? $res->json('id');
    }

    public function sendText(string $phone, string $text): ?string
    {
        return $this->post('/send-text', ['phone' => $phone, 'message' => $text]);
    }

    public function sendImage(string $phone, string $bytes, string $mime, ?string $caption = null): ?string
    {
        return $this->post('/send-image', ['phone' => $phone, 'image' => 'data:'.$mime.';base64,'.base64_encode($bytes), 'caption' => $caption]);
    }

    public function parse(Request $request): array
    {
        if ($request->boolean('fromMe') || $request->boolean('isGroup') || ! $request->filled('phone')) {
            return [];
        }
        $type = 'text';
        $url = null;
        $mime = null;
        $text = $request->input('text.message');
        foreach (['image' => 'imageUrl', 'document' => 'documentUrl', 'audio' => 'audioUrl'] as $kind => $field) {
            if ($request->filled($kind.'.'.$field)) {
                $type = $kind;
                $url = $request->input($kind.'.'.$field);
                $mime = $request->input($kind.'.mimeType');
                $text = $request->input($kind.'.caption', $text);
            }
        }
        $bytes = $url ? Media::download((string) $url) : null;

        return [new InboundMessage((string) $request->input('phone'), $type, $text, $request->input('senderName'), $request->input('messageId'), $bytes, $mime)];
    }
}
