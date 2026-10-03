<?php

namespace App\Modules\Messaging\Providers;

use App\Modules\Messaging\Models\MessagingChannel;
use Illuminate\Http\Client\PendingRequest;

/**
 * Evolution API v2 (NÃO OFICIAL, código aberto) — roda em servidor próprio (VPS/Docker); não roda
 * na hospedagem compartilhada.
 *
 * - Envio: POST {URL}/message/sendText/{instância} {number, text}; cabeçalho apikey.
 * - Webhook da instância (eventos MESSAGES_UPSERT e MESSAGES_UPDATE): URL do canal com ?token=.
 */
class EvolutionApiProvider extends UnofficialWhatsAppProvider
{
    public function sendText(MessagingChannel $channel, string $to, string $text): string
    {
        $response = $this->call(fn () => $this->client($channel)->post($this->url($channel, 'message/sendText'), [
            'number' => $to, 'text' => mb_substr($text, 0, 4096),
        ]), 'Evolution API');

        return (string) $response->json('key.id') ?: throw new MessagingException('Evolution API não devolveu o ID da mensagem.');
    }

    public function connectionStatus(MessagingChannel $channel): array
    {
        $state = (string) $this->call(fn () => $this->client($channel)->get($this->url($channel, 'instance/connectionState')), 'Evolution API')->json('instance.state');

        return $state === 'open'
            ? ['ok' => true, 'detail' => 'Instância conectada.']
            : ['ok' => false, 'detail' => 'Instância DESCONECTADA (estado: '.($state ?: 'desconhecido').'): leia o QR Code no painel da Evolution API.'];
    }

    public function parseWebhook(array $payload): array
    {
        $event = strtolower(str_replace('_', '.', (string) ($payload['event'] ?? '')));
        $items = isset($payload['data'][0]) ? $payload['data'] : [$payload['data'] ?? []];
        $events = [];

        foreach ($items as $d) {
            if ($event === 'messages.update') {
                $status = match (strtoupper((string) ($d['status'] ?? ''))) {
                    'SERVER_ACK' => 'sent', 'DELIVERY_ACK' => 'delivered', 'READ', 'PLAYED' => 'read', 'ERROR' => 'failed', default => null,
                };
                $id = $d['keyId'] ?? $d['key']['id'] ?? null;
                if ($status && $id) {
                    $events[] = new InboundEvent('status', (string) $id, $this->digits($d['remoteJid'] ?? $d['key']['remoteJid'] ?? null), status: $status,
                        error: $status === 'failed' ? 'falha no envio' : null);
                }

                continue;
            }

            $jid = (string) ($d['key']['remoteJid'] ?? '');
            if ($event !== 'messages.upsert' || ! empty($d['key']['fromMe']) || empty($d['key']['id'])
                || str_ends_with($jid, '@g.us') || str_ends_with($jid, '@broadcast') || str_ends_with($jid, '@newsletter')) {
                continue;
            }

            $m = $d['message'] ?? [];
            $text = $m['conversation'] ?? $m['extendedTextMessage']['text'] ?? $m['buttonsResponseMessage']['selectedDisplayText']
                ?? $m['templateButtonReplyMessage']['selectedDisplayText'] ?? $m['listResponseMessage']['title'] ?? null;
            if ($text === null) {
                $text = '['.str_replace('Message', '', (string) ($d['messageType'] ?? 'mensagem')).']'; // áudio/imagem: Fase 13
            }

            $events[] = new InboundEvent('message', (string) $d['key']['id'], $this->digits($jid), $d['pushName'] ?? null, (string) $text,
                $m['buttonsResponseMessage']['selectedButtonId'] ?? null, timestamp: isset($d['messageTimestamp']) ? (int) $d['messageTimestamp'] : null);
        }

        return $events;
    }

    private function url(MessagingChannel $channel, string $action): string
    {
        $base = rtrim((string) $channel->credential('evolution_url'), '/');
        $instance = (string) $channel->credential('evolution_instance');
        if ($base === '' || $instance === '') {
            throw new MessagingException('Evolution API não configurada (URL do servidor / instância).', false);
        }

        return $base.'/'.$action.'/'.rawurlencode($instance);
    }

    private function client(MessagingChannel $channel): PendingRequest
    {
        $key = (string) $channel->credential('evolution_api_key');
        if ($key === '') {
            throw new MessagingException('Evolution API não configurada (API key).', false);
        }

        return $this->http()->withHeaders(['apikey' => $key]);
    }
}
