<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Evolution API (WhatsApp não oficial, auto-hospedada). Ative "webhook base64" na instância. */
class EvolutionGateway implements WhatsAppGateway
{
    private function post(string $path, array $body): ?string
    {
        $url = rtrim((string) config('pesquisafarma.evolution.url'), '/').$path.'/'.config('pesquisafarma.evolution.instance');
        $res = Http::timeout(20)->withHeaders(['apikey' => (string) config('pesquisafarma.evolution.api_key')])->post($url, $body);
        if (! $res->successful()) {
            throw new RuntimeException('Evolution API recusou o envio ('.$res->status().').');
        }

        return $res->json('key.id');
    }

    public function sendText(string $phone, string $text): ?string
    {
        return $this->post('/message/sendText', ['number' => $phone, 'text' => $text]);
    }

    public function sendImage(string $phone, string $bytes, string $mime, ?string $caption = null): ?string
    {
        return $this->post('/message/sendMedia', ['number' => $phone, 'mediatype' => 'image', 'mimetype' => $mime, 'media' => base64_encode($bytes), 'caption' => $caption]);
    }

    public function parse(Request $request): array
    {
        $data = (array) $request->input('data', []);
        $jid = (string) data_get($data, 'key.remoteJid', '');
        if (data_get($data, 'key.fromMe') || $jid === '' || str_contains($jid, '@g.us')) {
            return [];
        }
        $msg = (array) data_get($data, 'message', []);
        $type = 'text';
        $mime = null;
        $text = $msg['conversation'] ?? data_get($msg, 'extendedTextMessage.text');
        foreach (['imageMessage' => 'image', 'documentMessage' => 'document', 'audioMessage' => 'audio'] as $key => $kind) {
            if (isset($msg[$key])) {
                $type = $kind;
                $mime = data_get($msg, $key.'.mimetype');
                $text = data_get($msg, $key.'.caption', $text);
            }
        }
        $b64 = $msg['base64'] ?? data_get($data, 'base64');
        $bytes = $b64 ? base64_decode((string) $b64, true) : null;

        return [new InboundMessage(explode('@', $jid)[0], $type, $text, data_get($data, 'pushName'), data_get($data, 'key.id'), $bytes ?: null, $mime)];
    }
}
