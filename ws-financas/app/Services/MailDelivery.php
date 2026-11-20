<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

/**
 * Envio de e-mails do sistema sem derrubar a tela: uma falha de SMTP (senha, porta bloqueada, certificado…) vira
 * aviso + registro no log, nunca HTTP 500. A mensagem de erro é higienizada (sem senha) e traduzida em uma dica.
 * O último resultado fica guardado para o painel do proprietário (Sistema e backups → E-mail).
 */
class MailDelivery
{
    public const LAST_KEY = 'wsf:mail:last';

    /** Link para criar/redefinir senha (convite de cliente, novo usuário, "esqueci a senha"). @return array{ok:bool, erro:?string, dica:?string} */
    public function sendPasswordLink(string $email, string $context): array
    {
        return $this->attempt($context, function () use ($email, $context) {
            // envio pedido pelo administrador (convite/reenvio/novo usuário): substitui o link anterior, sem o intervalo de 60 s
            if ($context !== 'esqueci_senha' && ($user = Password::broker()->getUser(['email' => mb_strtolower($email)]))) {
                Password::broker()->deleteToken($user);
            }
            try {
                $status = Password::sendResetLink(['email' => mb_strtolower($email)]);
            } catch (\Throwable $e) {
                // o link da tentativa que falhou é descartado: o próximo pedido não cai no intervalo de 60 s
                if ($user = Password::broker()->getUser(['email' => mb_strtolower($email)])) {
                    Password::broker()->deleteToken($user);
                }
                throw $e;
            }
            // usuário inexistente ou limite de 60 s entre pedidos não são falha de envio
            if (! in_array($status, [Password::RESET_LINK_SENT, Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
                throw new \RuntimeException('Envio não confirmado: '.$status);
            }
        });
    }

    /** E-mail de teste do painel do proprietário. */
    public function sendTest(string $to): array
    {
        return $this->attempt('teste', function () use ($to) {
            Mail::raw('Este é um e-mail de teste do '.config('wsf.brand.name').".\n\nSe você recebeu, o envio de convites e de recuperação de senha está funcionando.",
                fn ($m) => $m->to($to)->subject('Teste de e-mail — '.config('wsf.brand.name')));
        });
    }

    /** Relatório semanal/mensal de uma entidade (texto simples; passa pela mesma proteção contra credenciais). */
    public function sendReport(string $to, string $subject, string $body): array
    {
        return $this->attempt('relatorio', function () use ($to, $subject, $body) {
            $text = str_replace('*', '', $body)."\n\n—\n".config('wsf.brand.name').' · Para deixar de receber, ajuste em Relatórios automáticos no painel.';
            Mail::raw($text, fn ($m) => $m->to($to)->subject($subject));
        });
    }

    /** @return array{ok:bool, erro:?string, dica:?string} */
    private function attempt(string $context, callable $send): array
    {
        $t0 = microtime(true);
        try {
            $send();
            $r = ['ok' => true, 'erro' => null, 'dica' => null];
        } catch (\Throwable $e) {
            $msg = self::sanitize($e->getMessage());
            $r = ['ok' => false, 'erro' => class_basename($e).': '.$msg, 'dica' => self::hint($msg)];
            Log::error('E-mail não enviado', ['contexto' => $context, 'mailer' => config('mail.default'), 'host' => config('mail.mailers.smtp.host'),
                'porta' => config('mail.mailers.smtp.port'), 'erro' => $r['erro'], 'dica' => $r['dica']]);
        }
        $r['ms'] = (int) round((microtime(true) - $t0) * 1000);
        try {
            Cache::put(self::LAST_KEY, $r + ['contexto' => $context, 'quando' => now()->toIso8601String(), 'mailer' => (string) config('mail.default')], now()->addDays(30));
        } catch (\Throwable) {
        }

        return $r;
    }

    /** Remove a senha (e variações codificadas) de qualquer texto de erro. */
    public static function sanitize(string $message): string
    {
        $pw = (string) config('mail.mailers.smtp.password');
        if ($pw !== '') {
            $message = str_replace([$pw, base64_encode($pw), rawurlencode($pw)], '***', $message);
        }

        return mb_substr(trim(preg_replace('/\s+/', ' ', $message) ?? ''), 0, 400);
    }

    /** Dica prática para cada tipo de erro do SMTP. */
    public static function hint(string $message): string
    {
        $m = mb_strtolower($message);

        return match (true) {
            str_contains($m, 'envio bloqueado por segurança') => 'O e-mail continha uma credencial e NÃO foi enviado. Confira em Sistema e backups → E-mail quais campos do .env (APP_NAME, MAIL_FROM_NAME, WSF_BRAND_NAME) contêm senha ou chave e corrija; depois troque a senha exposta.',
            str_contains($m, 'authenticat') || str_contains($m, '535') || str_contains($m, 'username and password') || str_contains($m, 'credentials') => 'Usuário ou senha do e-mail recusados. Confira MAIL_USERNAME (e-mail completo) e MAIL_PASSWORD; se a senha tiver espaço, #, $, aspas ou barra, coloque-a entre aspas simples no .env.',
            str_contains($m, 'certificate') || str_contains($m, 'ssl operation failed') || str_contains($m, 'tls handshake') => 'Falha de segurança na conexão (SSL/TLS). Use o host do provedor exatamente como informado (smtp.titan.email) e a combinação porta/esquema correta.',
            str_contains($m, 'timed out') || str_contains($m, 'timeout') => 'Sem resposta do servidor de e-mail (porta bloqueada ou host errado). Tente MAIL_PORT=587 com MAIL_SCHEME=smtp, ou confirme com a hospedagem se a saída SMTP está liberada.',
            str_contains($m, 'connection could not be established') || str_contains($m, 'connection refused') || str_contains($m, 'getaddrinfo') || str_contains($m, 'name or service') => 'Não foi possível conectar ao servidor de e-mail. Confira MAIL_HOST e MAIL_PORT (Titan: smtp.titan.email, 465 com MAIL_SCHEME=smtps ou 587 com MAIL_SCHEME=smtp).',
            str_contains($m, '553') || str_contains($m, '550') || str_contains($m, 'sender') || str_contains($m, 'not owned') || str_contains($m, 'relay') => 'O servidor recusou o remetente. MAIL_FROM_ADDRESS precisa ser o mesmo e-mail de MAIL_USERNAME.',
            str_contains($m, 'maximum execution time') => 'O envio demorou além do limite do PHP. Ajuste MAIL_TIMEOUT (padrão 15 s) e confira host/porta.',
            default => 'Confira as configurações MAIL_* do .env e o log do dia em storage/logs.',
        };
    }
}
