<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp Business Platform — Cloud API oficial da Meta.
 *  - Resposta ao cliente: texto livre, permitido dentro da janela de 24 h aberta pela mensagem dele.
 *  - Avisos iniciados pela plataforma (lembretes): somente com modelo (template) aprovado + opt-in.
 *  - Webhook: GET de verificação (hub.verify_token) e POST assinado (X-Hub-Signature-256, HMAC do corpo com o App Secret).
 * Modo "mock" (desenvolvimento/homologação): nada sai para a Meta; mensagens ficam em `sent`.
 */
class WhatsAppClient
{
    /** @var list<array{to:string, type:string, body:string}> */
    public static array $sent = [];

    public function mock(): bool
    {
        return config('services.whatsapp.mode') !== 'cloud';
    }

    public function sendText(string $to, string $text): ?string
    {
        return $this->post(['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to, 'type' => 'text',
            'text' => ['preview_url' => false, 'body' => mb_substr($text, 0, 4096)]], $text);
    }

    /** @param list<string> $params */
    public function sendTemplate(string $to, string $name, string $lang, array $params): ?string
    {
        $components = $params ? [['type' => 'body', 'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], $params)]] : [];

        return $this->post(['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template',
            'template' => ['name' => $name, 'language' => ['code' => $lang], 'components' => $components]], '[modelo '.$name.'] '.implode(' | ', $params));
    }

    /** Marca como lida (dois tiques azuis) — boa prática da Meta. */
    public function markRead(string $wamid): void
    {
        if (! $this->mock() && $wamid !== '') {
            $this->post(['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => $wamid], null);
        }
    }

    public function verifySignature(Request $request): bool
    {
        if ($this->mock() && app()->environment('local', 'testing') && ! config('services.whatsapp.app_secret')) {
            return true;
        }
        $secret = (string) config('services.whatsapp.app_secret');
        $header = (string) $request->header('X-Hub-Signature-256', '');

        return $secret !== '' && str_starts_with($header, 'sha256=')
            && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $header);
    }

    /** @return list<array{wamid:string, from:string, text:?string, type:string}> */
    public function parse(array $payload): array
    {
        $out = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['messages'] ?? [] as $m) {
                    $type = $m['type'] ?? 'text';
                    $text = match ($type) {
                        'text' => $m['text']['body'] ?? null,
                        'button' => $m['button']['text'] ?? null,
                        'interactive' => $m['interactive']['button_reply']['title'] ?? ($m['interactive']['list_reply']['title'] ?? null),
                        default => null,
                    };
                    $out[] = ['wamid' => (string) ($m['id'] ?? ''), 'from' => (string) ($m['from'] ?? ''), 'text' => $text, 'type' => $type];
                }
            }
        }

        return $out;
    }

    private function post(array $body, ?string $logText): ?string
    {
        if ($this->mock()) {
            if ($logText !== null) {
                self::$sent[] = ['to' => (string) ($body['to'] ?? ''), 'type' => (string) $body['type'], 'body' => $logText];
            }

            return 'mock.'.bin2hex(random_bytes(8));
        }
        $url = 'https://graph.facebook.com/'.config('services.whatsapp.graph_version').'/'.config('services.whatsapp.phone_number_id').'/messages';
        try {
            $r = Http::withToken((string) config('services.whatsapp.access_token'))->acceptJson()->timeout(15)->retry(2, 500, throw: false)->post($url, $body);
        } catch (ConnectionException $e) {
            Log::error('WhatsApp: sem conexão com a Meta', ['erro' => $e->getMessage()]);

            return null;
        }
        if (! $r->successful()) {
            Log::error('WhatsApp: envio recusado', ['status' => $r->status(), 'erro' => $r->json('error.message')]);

            return null;
        }

        return $r->json('messages.0.id');
    }
}
