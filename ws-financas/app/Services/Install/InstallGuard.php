<?php

namespace App\Services\Install;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Acesso ao instalador SEM depender de APP_KEY, sessão ou banco:
 *
 *  1. Código temporário de instalação: gerado no servidor e gravado em
 *     storage/app/instalador/CODIGO-DE-INSTALACAO.txt (fora da pasta pública, 0600).
 *     Só quem acessa os arquivos pelo cPanel (o proprietário) consegue lê-lo. Vale 2 horas;
 *     após 5 tentativas erradas é trocado por outro.
 *  2. Sessão do instalador: cookie aleatório (HttpOnly, Secure, SameSite=Strict, só /instalar);
 *     no servidor fica apenas o hash. Token CSRF próprio em cada formulário.
 */
class InstallGuard
{
    public const COOKIE = 'wsf_instalador';

    private const TTL = 7200;

    private const MAX_ATTEMPTS = 5;

    public function dir(): string
    {
        $dir = storage_path('app/instalador');
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (! is_file($dir.'/.htaccess')) {
            @file_put_contents($dir.'/.htaccess', WebInstaller::DENY_HTACCESS);
        }

        return $dir;
    }

    public function codeFile(): string
    {
        return $this->dir().'/CODIGO-DE-INSTALACAO.txt';
    }

    /** Garante que exista um código válido (gera um novo se não houver ou se expirou). */
    public function ensureCode(): void
    {
        $state = $this->readJson('codigo.json');
        if ($state && ($state['expires'] ?? 0) > time() && is_file($this->codeFile())) {
            return;
        }
        $this->rotateCode();
    }

    public function rotateCode(): void
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $raw = '';
        for ($i = 0; $i < 24; $i++) {
            $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $code = implode('-', str_split($raw, 4));
        $expires = time() + self::TTL;
        $this->writeJson('codigo.json', ['hash' => hash('sha256', $code), 'expires' => $expires, 'attempts' => 0]);
        file_put_contents($this->codeFile(), "WS Finanças em Dia — código de instalação\n\n".$code."\n\nVálido até ".date('d/m/Y H:i', $expires)." (horário do servidor).\nCopie e cole na tela do instalador. Não compartilhe este código.\nEste arquivo é apagado automaticamente ao concluir a instalação.\n", LOCK_EX);
        @chmod($this->codeFile(), 0600);
    }

    /** @return array{ok: bool, message?: string} */
    public function checkCode(string $input): array
    {
        $input = strtoupper(trim(preg_replace('/\s+/', '', $input) ?? ''));
        $envToken = (string) env('WSF_INSTALL_TOKEN', '');
        if (strlen($envToken) >= 24 && hash_equals($envToken, trim($input))) {
            return ['ok' => true];
        }
        $state = $this->readJson('codigo.json');
        if (! $state || ($state['expires'] ?? 0) < time()) {
            $this->rotateCode();

            return ['ok' => false, 'message' => 'O código expirou. Um novo código foi gerado no arquivo — abra-o novamente pelo Gerenciador de Arquivos.'];
        }
        if (hash_equals((string) $state['hash'], hash('sha256', $input))) {
            return ['ok' => true];
        }
        $state['attempts'] = (int) ($state['attempts'] ?? 0) + 1;
        if ($state['attempts'] >= self::MAX_ATTEMPTS) {
            $this->rotateCode();

            return ['ok' => false, 'message' => 'Muitas tentativas erradas. Por segurança o código foi trocado — abra o arquivo novamente.'];
        }
        $this->writeJson('codigo.json', $state);

        return ['ok' => false, 'message' => 'Código incorreto. Tentativas restantes: '.(self::MAX_ATTEMPTS - $state['attempts']).'.'];
    }

    public function startSession(Request $request): Cookie
    {
        $id = bin2hex(random_bytes(32));
        $this->writeJson('sessao.json', ['hash' => hash('sha256', $id), 'csrf' => bin2hex(random_bytes(32)), 'expires' => time() + self::TTL,
            'ip' => $request->ip(), 'data' => []]);

        return new Cookie(self::COOKIE, $id, time() + self::TTL, '/instalar', null, $request->isSecure(), true, false, Cookie::SAMESITE_STRICT);
    }

    public function session(Request $request): ?array
    {
        $id = (string) $request->cookies->get(self::COOKIE, '');
        $s = $this->readJson('sessao.json');
        if ($id === '' || ! $s || ($s['expires'] ?? 0) < time() || ! hash_equals((string) $s['hash'], hash('sha256', $id))) {
            return null;
        }

        return $s;
    }

    public function csrf(Request $request): string
    {
        return (string) ($this->session($request)['csrf'] ?? '');
    }

    /** CSRF próprio + mesma origem. */
    public function validPost(Request $request): bool
    {
        $s = $this->session($request);
        if (! $s || ! hash_equals((string) $s['csrf'], (string) $request->input('_csrf'))) {
            return false;
        }
        $origin = (string) ($request->headers->get('Origin') ?: $request->headers->get('Referer'));

        return $origin === '' || parse_url($origin, PHP_URL_HOST) === $request->getHost();
    }

    public function remember(Request $request, string $key, mixed $value): void
    {
        $s = $this->session($request);
        if ($s) {
            $s['data'][$key] = $value;
            $this->writeJson('sessao.json', $s);
        }
    }

    public function recall(Request $request, string $key, mixed $default = null): mixed
    {
        return $this->session($request)['data'][$key] ?? $default;
    }

    /** Ao concluir: apaga código, sessão e cópias do .env (contêm senha do banco). */
    public function destroyAll(): void
    {
        foreach (glob($this->dir().'/*') ?: [] as $f) {
            if (is_file($f) && basename($f) !== '.htaccess') {
                @unlink($f);
            }
        }
    }

    private function readJson(string $name): ?array
    {
        $f = $this->dir().'/'.$name;
        $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;

        return is_array($d) ? $d : null;
    }

    private function writeJson(string $name, array $data): void
    {
        $f = $this->dir().'/'.$name;
        file_put_contents($f, json_encode($data), LOCK_EX);
        @chmod($f, 0600);
    }
}
