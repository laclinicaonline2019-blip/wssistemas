<?php

namespace App\Modules\Messaging\Providers;

use App\Modules\Messaging\Models\MessagingChannel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Base dos provedores NÃO OFICIAIS (WhatsApp Web conectado por QR Code: Z-API, Evolution API).
 *
 * Escolha da clínica, com aceite de risco registrado: violam os termos do WhatsApp e o número pode
 * ser bloqueado sem aviso. Não há modelos aprovados nem janela de 24 h — tudo vai como texto; os
 * lembretes pedem resposta "1/2/3" (botões não são confiáveis nesses serviços).
 *
 * Webhook: esses serviços não assinam os eventos; a URL leva um token secreto (?token=…) que é
 * comparado em tempo constante com o token do canal.
 */
abstract class UnofficialWhatsAppProvider implements WhatsAppProvider
{
    public function usesTemplates(): bool
    {
        return false;
    }

    public function sendTemplate(MessagingChannel $channel, string $to, string $name, string $language, array $bodyParams, array $buttonPayloads = []): string
    {
        throw new MessagingException('Provedor não oficial não usa modelos da Meta: envie o texto.', false);
    }

    public function verifySignature(MessagingChannel $channel, Request $request): bool
    {
        $token = (string) ($request->query('token') ?? $request->header('X-Webhook-Token', ''));

        return $token !== '' && hash_equals((string) $channel->verify_token, $token);
    }

    /** @return array{ok: bool, detail: string} */
    abstract public function connectionStatus(MessagingChannel $channel): array;

    /** Telefone só com dígitos (remove sufixos como "@s.whatsapp.net"). */
    protected function digits(?string $jid): string
    {
        return preg_replace('/\D/', '', strtok((string) $jid, '@:') ?: '');
    }

    protected function call(callable $send, string $service): Response
    {
        try {
            /** @var Response $response */
            $response = $send();
        } catch (ConnectionException) {
            throw new MessagingException("Sem conexão com a {$service}.");
        }
        if (! $response->successful()) {
            $detail = $response->json('error') ?? $response->json('message') ?? $response->json('response.message') ?? 'HTTP '.$response->status();
            $detail = is_array($detail) ? implode(' ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $detail)) : (string) $detail;

            throw new MessagingException("{$service} recusou: ".mb_substr($detail, 0, 300), $response->status() === 429 || $response->serverError());
        }

        return $response;
    }

    /** Mídia por URL temporária do serviço (só https). */
    protected function fetchUrl(string $url, ?string $mime, string $service): array
    {
        if (! str_starts_with($url, 'https://')) {
            throw new MessagingException("{$service}: URL de mídia inválida.", false);
        }
        $r = $this->call(fn () => Http::timeout(60)->get($url), $service);

        return [$r->body(), $mime ?: ($r->header('Content-Type') ?: null)];
    }

    protected function http(): PendingRequest
    {
        return Http::acceptJson()->asJson()->timeout(20);
    }
}
