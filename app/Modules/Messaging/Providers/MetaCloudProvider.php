<?php

namespace App\Modules\Messaging\Providers;

use App\Modules\Messaging\Models\MessagingChannel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp Business Platform — Cloud API (Meta).
 *
 * - Envio: POST https://graph.facebook.com/{versão}/{phone_number_id}/messages (Bearer do System User).
 * - Webhook: verificação GET (hub.verify_token) e POST assinado (X-Hub-Signature-256 = HMAC-SHA256 do
 *   corpo com o App Secret), comparado em tempo constante.
 * - Mensagens iniciadas pela clínica só com modelo aprovado; texto livre só na janela de 24 h.
 */
class MetaCloudProvider implements WhatsAppProvider
{
    public function sendTemplate(MessagingChannel $channel, string $to, string $name, string $language, array $bodyParams, array $buttonPayloads = []): string
    {
        $components = [];
        if ($bodyParams !== []) {
            $components[] = ['type' => 'body', 'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], array_values($bodyParams))];
        }
        foreach (array_values($buttonPayloads) as $i => $payload) {
            $components[] = ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => (string) $i, 'parameters' => [['type' => 'payload', 'payload' => $payload]]];
        }

        return $this->post($channel, [
            'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to, 'type' => 'template',
            'template' => ['name' => $name, 'language' => ['code' => $language], 'components' => $components],
        ]);
    }

    public function sendText(MessagingChannel $channel, string $to, string $text): string
    {
        return $this->post($channel, [
            'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to, 'type' => 'text',
            'text' => ['preview_url' => false, 'body' => mb_substr($text, 0, 4096)],
        ]);
    }

    public function verifySignature(MessagingChannel $channel, Request $request): bool
    {
        $secret = (string) $channel->credential('app_secret');
        $header = (string) $request->header('X-Hub-Signature-256', '');

        return $secret !== '' && str_starts_with($header, 'sha256=')
            && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $header);
    }

    public function parseWebhook(array $payload): array
    {
        $events = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
                $names = collect($value['contacts'] ?? [])->mapWithKeys(fn ($c) => [$c['wa_id'] ?? '' => $c['profile']['name'] ?? null]);

                foreach ($value['messages'] ?? [] as $m) {
                    $type = $m['type'] ?? 'text';
                    $text = match ($type) {
                        'text' => $m['text']['body'] ?? null,
                        'button' => $m['button']['text'] ?? null,
                        'interactive' => $m['interactive']['button_reply']['title'] ?? ($m['interactive']['list_reply']['title'] ?? null),
                        default => '['.$type.']', // áudio/imagem/documento: tratados na Fase 13 (IA multimodal)
                    };
                    $payloadId = $m['button']['payload'] ?? ($m['interactive']['button_reply']['id'] ?? null);

                    $events[] = new InboundEvent('message', (string) ($m['id'] ?? ''), (string) ($m['from'] ?? ''), $names[$m['from'] ?? ''] ?? null,
                        $text, $payloadId, timestamp: isset($m['timestamp']) ? (int) $m['timestamp'] : null, phoneNumberId: $phoneNumberId);
                }

                foreach ($value['statuses'] ?? [] as $s) {
                    $error = collect($s['errors'] ?? [])->map(fn ($e) => trim(($e['code'] ?? '').' '.($e['title'] ?? '')))->implode('; ') ?: null;
                    $events[] = new InboundEvent('status', (string) ($s['id'] ?? ''), $s['recipient_id'] ?? null, status: $s['status'] ?? null, error: $error,
                        timestamp: isset($s['timestamp']) ? (int) $s['timestamp'] : null, phoneNumberId: $phoneNumberId);
                }
            }
        }

        return $events;
    }

    private function post(MessagingChannel $channel, array $body): string
    {
        $token = $channel->credential('access_token');
        if (! $token || ! $channel->phone_number_id) {
            throw new MessagingException('WhatsApp não configurado (Phone Number ID / token de acesso).', false);
        }

        try {
            /** @var Response $response */
            $response = Http::withToken($token)->acceptJson()->asJson()->timeout(20)
                ->post("https://graph.facebook.com/{$channel->api_version}/{$channel->phone_number_id}/messages", $body);
        } catch (ConnectionException) {
            throw new MessagingException('Sem conexão com a API do WhatsApp.');
        }

        if ($response->successful() && ($id = $response->json('messages.0.id'))) {
            return $id;
        }

        $error = $response->json('error') ?? [];
        $message = trim(($error['code'] ?? $response->status()).' '.($error['error_data']['details'] ?? $error['message'] ?? 'erro desconhecido'));

        // 429/5xx: tenta de novo. 4xx (número inválido, modelo inexistente, fora da janela de 24 h): não adianta repetir.
        throw new MessagingException('WhatsApp recusou: '.$message, $response->status() === 429 || $response->serverError());
    }
}
