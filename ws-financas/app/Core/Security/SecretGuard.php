<?php

namespace App\Core\Security;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Email;

/**
 * Impede que credenciais do .env apareçam em e-mails.
 *
 * 1) Na inicialização: se o nome do sistema (APP_NAME), o nome do remetente (MAIL_FROM_NAME) ou a marca (WSF_BRAND_NAME)
 *    contiverem alguma credencial, o valor é trocado pelo nome padrão e o campo fica apontado no painel.
 * 2) Antes de cada envio: o e-mail inteiro (assunto, remetente, cabeçalhos, texto e HTML) é conferido; se contiver
 *    alguma credencial, o envio é BLOQUEADO (exceção) — nada sai com a senha. O log registra só o campo, nunca o valor.
 */
final class SecretGuard
{
    public const SAFE_NAME = 'WS Finanças em Dia';

    /** Campos exibidos ao destinatário que vieram do .env com credencial dentro (preenchido em boot()). @var list<string> */
    private static array $issues = [];

    /** @return array<string, string> nome legível => valor (só credenciais com 6+ caracteres) */
    public static function secrets(): array
    {
        $list = [
            'MAIL_PASSWORD' => config('mail.mailers.smtp.password'),
            'DB_PASSWORD' => config('database.connections.'.config('database.default').'.password'),
            'APP_KEY' => config('app.key'),
            'AUDIT_HMAC_KEY' => config('wsf.security.audit_hmac_key'),
            'BACKUP_PASSWORD' => config('wsf.backup.password'),
            'WHATSAPP_ACCESS_TOKEN' => config('services.whatsapp.access_token'),
            'WHATSAPP_APP_SECRET' => config('services.whatsapp.app_secret'),
            'WHATSAPP_VERIFY_TOKEN' => config('services.whatsapp.verify_token'),
            'ASAAS_API_KEY' => config('services.asaas.api_key'),
            'ASAAS_WEBHOOK_TOKEN' => config('services.asaas.webhook_token'),
            'ANTHROPIC_API_KEY' => config('services.anthropic.key'),
        ];
        $out = [];
        foreach ($list as $name => $v) {
            $v = trim((string) $v);
            if (mb_strlen($v) >= 6) {
                $out[$name] = $v;
                if (str_starts_with($v, 'base64:')) {
                    $out[$name.' (sem prefixo)'] = substr($v, 7);
                }
            }
        }

        return $out;
    }

    /** Nome da credencial encontrada no texto (em claro ou codificada), ou null. */
    public static function find(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }
        $variants = fn (string $t) => [$t, quoted_printable_decode($t), html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8'), rawurldecode($t)];
        foreach (self::secrets() as $name => $secret) {
            foreach ($variants($text) as $candidate) {
                if (str_contains($candidate, $secret)) {
                    return $name;
                }
            }
            if (str_contains($text, base64_encode($secret))) {
                return $name;
            }
        }

        return null;
    }

    /** Na inicialização: nomes exibidos no e-mail nunca podem carregar credenciais. */
    public static function protectDisplayConfig(): void
    {
        self::$issues = [];
        foreach (['app.name' => 'APP_NAME', 'mail.from.name' => 'MAIL_FROM_NAME', 'wsf.brand.name' => 'WSF_BRAND_NAME'] as $key => $envName) {
            if (($secret = self::find((string) config($key))) !== null) {
                config([$key => self::SAFE_NAME]);
                self::$issues[] = $envName.' contém '.$secret;
            }
        }
    }

    /** @return list<string> */
    public static function issues(): array
    {
        return self::$issues;
    }

    /** Ouvinte de MessageSending: bloqueia qualquer e-mail que contenha credencial. */
    public static function inspect(MessageSending $event): void
    {
        $m = $event->message;
        $parts = ['assunto' => $m->getSubject()];
        foreach ($m->getHeaders()->all() as $h) {
            $parts['cabeçalho '.$h->getName()] = $h->getBodyAsString();
        }
        if ($m instanceof Email) {
            $parts['texto'] = is_resource($m->getTextBody()) ? stream_get_contents($m->getTextBody()) : (string) $m->getTextBody();
            $parts['html'] = is_resource($m->getHtmlBody()) ? stream_get_contents($m->getHtmlBody()) : (string) $m->getHtmlBody();
        }
        foreach ($parts as $where => $text) {
            if (($secret = self::find((string) $text)) !== null) {
                Log::critical('E-mail BLOQUEADO: continha uma credencial', ['campo' => $where, 'credencial' => $secret, 'assunto_tem_credencial' => $where === 'assunto']);

                throw new CredentialInEmail('Envio bloqueado por segurança: o e-mail continha uma credencial ('.$secret.') em '.$where.'. Nenhuma mensagem foi enviada.');
            }
        }
    }
}
