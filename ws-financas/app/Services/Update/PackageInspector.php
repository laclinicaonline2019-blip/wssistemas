<?php

namespace App\Services\Update;

use App\Core\Support\BusinessRule;
use ZipArchive;

/**
 * Validação de um pacote de atualização ANTES de qualquer alteração:
 *  - manifesto (wsf-update.json): aplicativo, versão, versão mínima de origem, PHP mínimo;
 *  - assinatura Ed25519 (wsf-update.sig) quando a plataforma tem chave pública configurada;
 *  - caminhos seguros (sem "..", caminho absoluto, link simbólico, .env ou conteúdo de storage);
 *  - espaço em disco e arquivos essenciais presentes.
 */
class PackageInspector
{
    /** Pastas/arquivos substituídos pela atualização. .env e storage/ NUNCA são tocados. */
    public const DIRS = ['app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources', 'routes', 'vendor'];

    public const FILES = ['artisan', 'composer.json', 'composer.lock', 'VERSION', 'wsf-update.json', 'CHECKSUMS.sha256', 'wsf-update.sig', 'README.md'];

    /** Itens aceitos no pacote mas não aplicados (documentação, exemplos, esqueleto do storage). */
    public const IGNORED = ['docs', 'deploy', 'storage', 'tests', '.env.example', '.gitignore', '.gitattributes', '.editorconfig', 'phpunit.xml', '.htaccess'];

    public const REQUIRED = ['artisan', 'bootstrap/app.php', 'public/index.php', 'vendor/autoload.php', 'VERSION', 'wsf-update.json', 'CHECKSUMS.sha256'];

    /** @return array{prefix:string, manifest:array, signed:bool, signature_ok:?bool, entries:int, bytes:int, sha256:string, warnings:list<string>} */
    public function inspect(string $zipPath, string $currentVersion): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new BusinessRule('A extensão PHP "zip" não está ativa. Ative em cPanel → Selecionar versão do PHP → Extensões, ou faça a atualização manual (docs).');
        }
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new BusinessRule('Arquivo ZIP inválido ou corrompido.');
        }
        try {
            $prefix = $this->prefix($zip);
            $manifestRaw = $zip->getFromName($prefix.'wsf-update.json');
            $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
            if (! is_array($manifest) || ($manifest['app'] ?? '') !== 'ws-financas' || empty($manifest['version'])) {
                throw new BusinessRule('Este pacote não é uma atualização do WS Finanças em Dia (manifesto ausente ou inválido).');
            }
            $version = (string) $manifest['version'];
            if (! preg_match('/^\d+\.\d+\.\d+([.-][A-Za-z0-9.-]+)?$/', $version)) {
                throw new BusinessRule('Versão inválida no manifesto.');
            }
            if (version_compare($version, $currentVersion, '<=')) {
                throw new BusinessRule("O pacote é da versão {$version}, que não é mais nova que a instalada ({$currentVersion}). Para voltar de versão use a reversão.");
            }
            if (! empty($manifest['min_from']) && version_compare($currentVersion, (string) $manifest['min_from'], '<')) {
                throw new BusinessRule("Este pacote exige a versão {$manifest['min_from']} ou superior instalada antes (atual: {$currentVersion}). Aplique as atualizações intermediárias.");
            }
            if (! empty($manifest['php_min']) && version_compare(PHP_VERSION, (string) $manifest['php_min'], '<')) {
                throw new BusinessRule("A versão {$version} exige PHP {$manifest['php_min']} ou superior (atual: ".PHP_VERSION.'). Mude em cPanel → MultiPHP Manager.');
            }

            [$signed, $signatureOk] = $this->signature($zip, $prefix, (string) $manifestRaw);
            $publicKey = (string) config('wsf.update.public_key');
            if ($publicKey !== '' && $signatureOk !== true) {
                throw new BusinessRule($signed ? 'Assinatura do pacote INVÁLIDA — o arquivo pode ter sido alterado. Não aplique.' : 'Pacote sem assinatura, e esta instalação exige pacotes assinados (WSF_UPDATE_PUBLIC_KEY).');
            }

            $bytes = 0;
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                $name = (string) $st['name'];
                $this->assertSafe($zip, $i, $name, $prefix);
                $rel = substr($name, strlen($prefix));
                if ($rel === '') {
                    continue;
                }
                $names[$rel] = true;
                $bytes += (int) $st['size'];
            }
            foreach (self::REQUIRED as $req) {
                if (! isset($names[$req])) {
                    throw new BusinessRule('Pacote incompleto: falta '.$req.'.');
                }
            }
            $warnings = [];
            $free = @disk_free_space(base_path());
            if ($free !== false && $free < $bytes * 2.5) {
                throw new BusinessRule('Espaço em disco insuficiente: são necessários ~'.round($bytes * 2.5 / 1048576).' MB livres (há '.round($free / 1048576).' MB). Apague backups antigos ou peça mais espaço.');
            }
            if (! $signed) {
                $warnings[] = 'Pacote sem assinatura digital: confira o código SHA-256 abaixo com o informado pelo fornecedor antes de aplicar.';
            }

            return ['prefix' => $prefix, 'manifest' => $manifest, 'signed' => $signed, 'signature_ok' => $signatureOk, 'entries' => count($names),
                'bytes' => $bytes, 'sha256' => hash_file('sha256', $zipPath), 'warnings' => $warnings];
        } finally {
            $zip->close();
        }
    }

    /** O manifesto pode estar na raiz do ZIP ou dentro de uma única pasta (ws-financas-x.y.z/). */
    private function prefix(ZipArchive $zip): string
    {
        if ($zip->locateName('wsf-update.json') !== false) {
            return '';
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string) $zip->getNameIndex($i);
            if (preg_match('#^([^/]+/)wsf-update\.json$#', $n, $m)) {
                return $m[1];
            }
        }
        throw new BusinessRule('Este pacote não é uma atualização do WS Finanças em Dia (manifesto wsf-update.json não encontrado).');
    }

    /** @return array{0: bool, 1: ?bool} [tem assinatura, assinatura válida (null = sem chave para conferir)] */
    private function signature(ZipArchive $zip, string $prefix, string $manifestRaw): array
    {
        $sig = $zip->getFromName($prefix.'wsf-update.sig');
        if (! is_string($sig) || trim($sig) === '') {
            return [false, null];
        }
        $key = (string) config('wsf.update.public_key');
        if ($key === '' || ! function_exists('sodium_crypto_sign_verify_detached')) {
            return [true, null];
        }
        $rawSig = base64_decode(trim($sig), true);
        $rawKey = base64_decode($key, true);
        if ($rawSig === false || $rawKey === false || strlen($rawKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($rawSig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return [true, false];
        }

        return [true, sodium_crypto_sign_verify_detached($rawSig, $manifestRaw, $rawKey)];
    }

    private function assertSafe(ZipArchive $zip, int $i, string $name, string $prefix): void
    {
        $bad = $name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name)
            || preg_match('/^[A-Za-z]:/', $name) || ($prefix !== '' && ! str_starts_with($name, $prefix));
        if ($bad) {
            throw new BusinessRule('Pacote recusado: caminho inseguro dentro do ZIP ('.mb_substr($name, 0, 80).').');
        }
        if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
            throw new BusinessRule('Pacote recusado: contém link simbólico ('.mb_substr($name, 0, 80).').');
        }
        $rel = substr($name, strlen($prefix));
        if ($rel === '') {
            return;
        }
        $top = explode('/', $rel)[0];
        if ($top === '.env' || str_starts_with($top, '.env.') && $top !== '.env.example') {
            throw new BusinessRule('Pacote recusado: contém arquivo .env (configurações e senhas nunca vêm no pacote).');
        }
        if (! in_array($top, self::DIRS, true) && ! in_array($top, self::FILES, true) && ! in_array($top, self::IGNORED, true)) {
            throw new BusinessRule('Pacote recusado: item inesperado na raiz ('.mb_substr($top, 0, 60).').');
        }
        if ($top === 'storage' && ! preg_match('#/(\.gitignore|\.htaccess)$|/$#', $rel)) {
            throw new BusinessRule('Pacote recusado: contém dados dentro de storage/ ('.mb_substr($rel, 0, 80).').');
        }
    }
}
