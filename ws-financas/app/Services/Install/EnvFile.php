<?php

namespace App\Services\Install;

use App\Core\Support\BusinessRule;
use Dotenv\Dotenv;

/**
 * Leitura e gravação segura do .env (sem terminal):
 *  - recusa quebras de linha e caracteres de controle (impede injetar variáveis novas);
 *  - escolhe a forma de aspas que o phpdotenv lê literalmente e confere a ida e volta;
 *  - grava em arquivo temporário + rename (atômico) com permissão 0600 e guarda cópia do anterior.
 */
class EnvFile
{
    public function __construct(private readonly string $path) {}

    public static function forApp(): self
    {
        return new self(app()->environmentFilePath());
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function writable(): bool
    {
        return $this->exists() ? is_writable($this->path) : is_writable(dirname($this->path));
    }

    /** @return array<string, string|null> */
    public function values(): array
    {
        return $this->exists() ? Dotenv::parse((string) file_get_contents($this->path)) : [];
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $v = $this->values()[$key] ?? null;

        return $v === null || $v === '' ? $default : $v;
    }

    /** @param array<string, string> $values */
    public function set(array $values, ?string $backupDir = null): void
    {
        $content = $this->exists() ? (string) file_get_contents($this->path) : '';
        foreach ($values as $key => $value) {
            if (! preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $key)) {
                throw new BusinessRule('Nome de variável inválido: '.$key);
            }
            $line = $key.'='.self::encode((string) $value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            $content = preg_match($pattern, $content)
                ? preg_replace_callback($pattern, fn () => $line, $content, 1)
                : rtrim($content, "\n")."\n".$line."\n";
        }

        $parsed = Dotenv::parse($content);
        foreach ($values as $key => $value) {
            if ((string) ($parsed[$key] ?? '') !== (string) $value) {
                throw new BusinessRule('Não foi possível gravar '.$key.' no .env com segurança.');
            }
        }

        if ($backupDir && $this->exists()) {
            if (! is_dir($backupDir)) {
                @mkdir($backupDir, 0700, true);
            }
            $copy = $backupDir.'/env-'.date('Ymd-His').'-'.bin2hex(random_bytes(2)).'.bak';
            @copy($this->path, $copy);
            @chmod($copy, 0600);
        }

        $tmp = $this->path.'.tmp-'.bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new BusinessRule('Sem permissão para gravar o arquivo .env. No cPanel, dê permissão 644/600 ao .env e 755 à pasta do sistema.');
        }
        @chmod($tmp, 0600);
        if (! @rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new BusinessRule('Não foi possível substituir o .env.');
        }
        @chmod($this->path, 0600);
    }

    /** Valor no formato que o phpdotenv lê exatamente igual. */
    public static function encode(string $v): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $v)) {
            throw new BusinessRule('Valores do .env não podem conter quebras de linha ou caracteres de controle.');
        }
        if ($v === '' || preg_match('/^[A-Za-z0-9_\-.:\/@+=,]+$/', $v)) {
            return $v;
        }
        if (! str_contains($v, "'")) {
            return "'".$v."'";
        }
        if (! preg_match('/["\\\\$]/', $v)) {
            return '"'.$v.'"';
        }
        throw new BusinessRule('O valor contém aspas simples junto com aspas duplas, "$" ou "\\" — combinação não suportada no .env. Troque a senha por uma sem essa combinação.');
    }

    public static function generateAppKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }
}
