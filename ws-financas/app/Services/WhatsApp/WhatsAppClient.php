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

    /** Resultado do último envio à Meta (sem dados privados): http, codigo e titulo do erro. */
    public ?array $lastResult = null;

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
        return in_array($this->signatureStatus($request), ['ok', 'dispensada_dev'], true);
    }

    /**
     * Resultado da conferência da assinatura X-Hub-Signature-256 (HMAC-SHA256 do corpo bruto com o App Secret).
     * ok · dispensada_dev (só mock + local/testing sem segredo) · segredo_vazio · sem_cabecalho · formato_invalido · nao_confere
     */
    public function signatureStatus(Request $request): string
    {
        $secret = (string) config('services.whatsapp.app_secret');
        if ($this->mock() && app()->environment('local', 'testing') && $secret === '') {
            return 'dispensada_dev';
        }
        if ($secret === '') {
            return 'segredo_vazio';
        }
        $header = (string) $request->header('X-Hub-Signature-256', '');
        if ($header === '') {
            return 'sem_cabecalho';
        }
        if (! preg_match('/^sha256=[0-9a-f]{64}$/i', $header)) {
            return 'formato_invalido';
        }

        return hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), strtolower($header)) ? 'ok' : 'nao_confere';
    }

    /**
     * Situação da configuração sem revelar valores (para o diagnóstico do painel).
     *
     * @return list<array{item:string, ok:bool, info:string}>
     */
    public function configReport(): array
    {
        $secret = (string) config('services.whatsapp.app_secret');
        $token = (string) config('services.whatsapp.access_token');
        $verify = (string) config('services.whatsapp.verify_token');
        $pnid = (string) config('services.whatsapp.phone_number_id');
        $clean = fn (string $v) => $v === trim($v, " \t\n\r\"'");

        return [
            ['item' => 'Modo', 'ok' => ! $this->mock(), 'info' => $this->mock() ? 'mock (respostas NÃO são enviadas à Meta)' : 'cloud (API oficial)'],
            ['item' => 'App Secret (WHATSAPP_APP_SECRET)', 'ok' => (bool) preg_match('/^[0-9a-f]{32}$/i', $secret),
                'info' => $secret === '' ? 'vazio — toda mensagem será recusada' : (strlen($secret).' caracteres'.($clean($secret) ? '' : ', com espaço/aspas nas pontas').(preg_match('/^[0-9a-f]{32}$/i', $secret) ? '' : ' — o App Secret da Meta tem 32 caracteres hexadecimais; confira se não colou outro valor'))],
            ['item' => 'Token de verificação (WHATSAPP_VERIFY_TOKEN)', 'ok' => $verify !== '' && $clean($verify), 'info' => $verify === '' ? 'vazio' : strlen($verify).' caracteres'],
            ['item' => 'Phone Number ID (WHATSAPP_PHONE_NUMBER_ID)', 'ok' => (bool) preg_match('/^\d{6,20}$/', $pnid), 'info' => $pnid === '' ? 'vazio' : (preg_match('/^\d{6,20}$/', $pnid) ? 'numérico, '.strlen($pnid).' dígitos' : 'não é só números — é o ID do número, não o telefone')],
            ['item' => 'Token de acesso (WHATSAPP_ACCESS_TOKEN)', 'ok' => strlen($token) > 50 && $clean($token), 'info' => $token === '' ? 'vazio' : strlen($token).' caracteres'.($clean($token) ? '' : ', com espaço/aspas nas pontas')],
            ['item' => 'Versão da Graph API', 'ok' => (bool) preg_match('/^v\d+\.\d+$/', (string) config('services.whatsapp.graph_version')), 'info' => (string) config('services.whatsapp.graph_version')],
        ];
    }

    /** Eventos de status das mensagens enviadas (sent/delivered/read/failed). @return list<array{wamid:string, status:string, errors:list<array>}> */
    public function parseStatuses(array $payload): array
    {
        $out = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['statuses'] ?? [] as $st) {
                    $out[] = ['wamid' => (string) ($st['id'] ?? ''), 'status' => (string) ($st['status'] ?? ''),
                        'errors' => array_map(fn ($e) => ['codigo' => $e['code'] ?? null, 'titulo' => mb_substr((string) ($e['title'] ?? ''), 0, 120)], (array) ($st['errors'] ?? []))];
                }
            }
        }

        return $out;
    }

    /** @return list<array{wamid:string, from:string, text:?string, type:string, timestamp:?int}> */
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
                    $out[] = ['wamid' => (string) ($m['id'] ?? ''), 'from' => (string) ($m['from'] ?? ''), 'text' => $text, 'type' => $type, 'timestamp' => isset($m['timestamp']) ? (int) $m['timestamp'] : null];
                }
            }
        }

        return $out;
    }

    private function post(array $body, ?string $logText): ?string
    {
        $this->lastResult = null;
        if ($this->mock()) {
            $this->lastResult = ['http' => null, 'modo' => 'mock'];
            if ($logText !== null) {
                self::$sent[] = ['to' => (string) ($body['to'] ?? ''), 'type' => (string) $body['type'], 'body' => $logText];
            }

            return 'mock.'.bin2hex(random_bytes(8));
        }
        $url = 'https://graph.facebook.com/'.config('services.whatsapp.graph_version').'/'.config('services.whatsapp.phone_number_id').'/messages';
        try {
            $r = Http::withToken((string) config('services.whatsapp.access_token'))->acceptJson()->timeout(15)->retry(2, 500, throw: false)->post($url, $body);
        } catch (ConnectionException $e) {
            Log::error('WhatsApp: sem conexão com a Meta', ['erro' => class_basename($e)]);
            $this->lastResult = ['http' => null, 'erro' => 'sem_conexao'];

            return null;
        }
        $this->lastResult = ['http' => $r->status(), 'codigo' => $r->json('error.code'), 'subcodigo' => $r->json('error.error_subcode'), 'titulo' => mb_substr((string) $r->json('error.message'), 0, 160) ?: null];
        if (! $r->successful()) {
            Log::error('WhatsApp: envio recusado', ['status' => $r->status(), 'codigo' => $r->json('error.code'), 'erro' => mb_substr((string) $r->json('error.message'), 0, 200)]);

            return null;
        }

        return $r->json('messages.0.id');
    }
}
