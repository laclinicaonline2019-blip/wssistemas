<?php

namespace App\Modules\Messaging\Providers;

use App\Modules\Messaging\Models\MessagingChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * MOCK: nada sai do sistema. Usado na demonstração e nos testes; identificado como MOCK
 * em todas as telas. Respostas do paciente são simuladas pela tela da conversa.
 */
class MockWhatsAppProvider implements WhatsAppProvider
{
    public function sendTemplate(MessagingChannel $channel, string $to, string $name, string $language, array $bodyParams, array $buttonPayloads = []): string
    {
        return 'mock.'.Str::lower((string) Str::ulid());
    }

    public function sendText(MessagingChannel $channel, string $to, string $text): string
    {
        return 'mock.'.Str::lower((string) Str::ulid());
    }

    public function verifySignature(MessagingChannel $channel, Request $request): bool
    {
        $token = (string) $request->header('X-Mock-Token', '');

        return $token !== '' && hash_equals((string) $channel->verify_token, $token);
    }

    public function parseWebhook(array $payload): array
    {
        return (new MetaCloudProvider)->parseWebhook($payload); // mesmo formato da Meta
    }
}
