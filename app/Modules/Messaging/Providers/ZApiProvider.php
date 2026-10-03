<?php

namespace App\Modules\Messaging\Providers;

use App\Modules\Messaging\Models\MessagingChannel;
use Illuminate\Http\Client\PendingRequest;

/**
 * Z-API (NÃO OFICIAL) — serviço brasileiro em nuvem; não exige servidor próprio.
 *
 * - Envio: POST https://api.z-api.io/instances/{instância}/token/{token}/send-text {phone, message};
 *   cabeçalho Client-Token (token de segurança da conta), quando configurado.
 * - Webhooks (painel da Z-API → "Ao receber" e "Status da mensagem"): URL do canal com ?token=.
 */
class ZApiProvider extends UnofficialWhatsAppProvider
{
    public const BASE = 'https://api.z-api.io';

    public function sendText(MessagingChannel $channel, string $to, string $text): string
    {
        $response = $this->call(fn () => $this->client($channel)->post($this->url($channel, 'send-text'), [
            'phone' => $to, 'message' => mb_substr($text, 0, 4096),
        ]), 'Z-API');

        return (string) ($response->json('messageId') ?: $response->json('id') ?: $response->json('zaapId'))
            ?: throw new MessagingException('Z-API não devolveu o ID da mensagem.');
    }

    public function connectionStatus(MessagingChannel $channel): array
    {
        $r = $this->call(fn () => $this->client($channel)->get($this->url($channel, 'status')), 'Z-API');
        $ok = (bool) $r->json('connected');

        return ['ok' => $ok, 'detail' => $ok
            ? 'Instância conectada'.($r->json('smartphoneConnected') === false ? ' (celular sem internet)' : '').'.'
            : 'Instância DESCONECTADA: '.($r->json('error') ?: 'leia o QR Code no painel da Z-API.')];
    }

    public function parseWebhook(array $payload): array
    {
        $type = (string) ($payload['type'] ?? '');

        if ($type === 'MessageStatusCallback') {
            $status = match (strtoupper((string) ($payload['status'] ?? ''))) {
                'SENT' => 'sent', 'RECEIVED', 'DELIVERED' => 'delivered', 'READ', 'READ_BY_ME', 'PLAYED' => 'read', 'FAILED', 'ERROR' => 'failed', default => null,
            };

            return $status ? array_map(fn ($id) => new InboundEvent('status', (string) $id, $this->digits($payload['phone'] ?? null), status: $status,
                error: $status === 'failed' ? (string) ($payload['error'] ?? 'falha no envio') : null), (array) ($payload['ids'] ?? [])) : [];
        }

        // Só mensagens recebidas de contatos (ignora grupos, canais, status e o que o próprio número enviou).
        if ($type !== 'ReceivedCallback' || ! empty($payload['fromMe']) || ! empty($payload['isGroup']) || ! empty($payload['isNewsletter'])
            || ! empty($payload['broadcast']) || ! empty($payload['isStatusReply']) || empty($payload['messageId'])) {
            return [];
        }

        $text = $payload['text']['message'] ?? $payload['buttonsResponseMessage']['message'] ?? $payload['listResponseMessage']['title']
            ?? $payload['buttonReply']['message'] ?? null;
        if ($text === null) {
            $kind = collect(['audio', 'image', 'video', 'document', 'sticker', 'location', 'contact'])->first(fn ($k) => isset($payload[$k])) ?? 'mensagem';
            $text = '['.$kind.']'; // tratados na Fase 13 (IA multimodal)
        }

        return [new InboundEvent('message', (string) $payload['messageId'], $this->digits($payload['phone'] ?? null),
            $payload['senderName'] ?? $payload['chatName'] ?? null, (string) $text, $payload['buttonsResponseMessage']['buttonId'] ?? null,
            timestamp: isset($payload['momment']) ? intdiv((int) $payload['momment'], 1000) : null)];
    }

    private function url(MessagingChannel $channel, string $action): string
    {
        $instance = (string) $channel->credential('zapi_instance_id');
        $token = (string) $channel->credential('zapi_token');
        if ($instance === '' || $token === '') {
            throw new MessagingException('Z-API não configurada (ID da instância / token).', false);
        }

        return self::BASE.'/instances/'.rawurlencode($instance).'/token/'.rawurlencode($token).'/'.$action;
    }

    private function client(MessagingChannel $channel): PendingRequest
    {
        $client = $this->http();

        return ($ct = $channel->credential('zapi_client_token')) ? $client->withHeaders(['Client-Token' => $ct]) : $client;
    }
}
