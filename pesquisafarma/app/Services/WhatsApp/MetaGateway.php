<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** WhatsApp oficial (Cloud API da Meta). */
class MetaGateway implements WhatsAppGateway
{
    private const API = 'https://graph.facebook.com/v21.0/';

    private function http()
    {
        return Http::timeout(20)->withToken((string) config('pesquisafarma.meta.access_token'));
    }

    private function send(array $payload): ?string
    {
        $res = $this->http()->post(self::API.config('pesquisafarma.meta.phone_number_id').'/messages', ['messaging_product' => 'whatsapp'] + $payload);
        if (! $res->successful()) {
            throw new RuntimeException('Meta recusou o envio ('.$res->status().').');
        }

        return $res->json('messages.0.id');
    }

    public function sendText(string $phone, string $text): ?string
    {
        return $this->send(['to' => $phone, 'type' => 'text', 'text' => ['body' => $text, 'preview_url' => true]]);
    }

    public function sendImage(string $phone, string $bytes, string $mime, ?string $caption = null): ?string
    {
        $upload = $this->http()->attach('file', $bytes, 'imagem', ['Content-Type' => $mime])
            ->post(self::API.config('pesquisafarma.meta.phone_number_id').'/media', ['messaging_product' => 'whatsapp', 'type' => $mime]);

        return $this->send(['to' => $phone, 'type' => 'image', 'image' => ['id' => $upload->json('id'), 'caption' => $caption]]);
    }

    public function parse(Request $request): array
    {
        $out = [];
        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                $name = data_get($value, 'contacts.0.profile.name');
                foreach ((array) ($value['messages'] ?? []) as $m) {
                    $type = (string) ($m['type'] ?? 'text');
                    $text = data_get($m, 'text.body');
                    $bytes = null;
                    $mime = null;
                    if (in_array($type, ['image', 'document', 'audio'], true)) {
                        $text = data_get($m, $type.'.caption');
                        $mime = data_get($m, $type.'.mime_type');
                        $bytes = $this->download((string) data_get($m, $type.'.id'));
                    } elseif ($type !== 'text') {
                        $text = data_get($m, 'button.text') ?? data_get($m, 'interactive.button_reply.title');
                        $type = 'text';
                    }
                    $out[] = new InboundMessage((string) $m['from'], $type, $text, $name, $m['id'] ?? null, $bytes, $mime);
                }
            }
        }

        return $out;
    }

    private function download(string $mediaId): ?string
    {
        if ($mediaId === '') {
            return null;
        }
        $url = $this->http()->get(self::API.$mediaId)->json('url');
        if (! $url) {
            throw new RuntimeException('Mídia não encontrada na Meta.');
        }

        return $this->http()->get($url)->body();
    }
}
