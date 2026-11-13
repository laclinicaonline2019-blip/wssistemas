<?php

/*
 * Assinatura Ed25519 dos pacotes de atualização (uso do FORNECEDOR, nunca no servidor do cliente).
 *
 *   php deploy/assinar-pacote.php gerar-chaves          → imprime chave pública (vai no .env do cliente: WSF_UPDATE_PUBLIC_KEY)
 *                                                          e chave secreta (guarde em local seguro / segredo do CI)
 *   WSF_UPDATE_SIGNING_KEY=... php deploy/assinar-pacote.php assinar caminho/wsf-update.json   → grava wsf-update.sig
 */
if ($argc < 2 || ! function_exists('sodium_crypto_sign_keypair')) {
    fwrite(STDERR, "uso: gerar-chaves | assinar <wsf-update.json>  (requer ext-sodium)\n");
    exit(1);
}
if ($argv[1] === 'gerar-chaves') {
    $kp = sodium_crypto_sign_keypair();
    echo 'WSF_UPDATE_PUBLIC_KEY='.base64_encode(sodium_crypto_sign_publickey($kp)).PHP_EOL;
    echo 'WSF_UPDATE_SIGNING_KEY='.base64_encode(sodium_crypto_sign_secretkey($kp)).'   (SECRETA — não publique)'.PHP_EOL;
    exit(0);
}
if ($argv[1] === 'assinar' && isset($argv[2])) {
    $secret = base64_decode((string) getenv('WSF_UPDATE_SIGNING_KEY'), true);
    if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        fwrite(STDERR, "WSF_UPDATE_SIGNING_KEY ausente ou inválida.\n");
        exit(1);
    }
    $manifest = file_get_contents($argv[2]);
    file_put_contents(dirname($argv[2]).'/wsf-update.sig', base64_encode(sodium_crypto_sign_detached($manifest, $secret)).PHP_EOL);
    echo "Assinado.\n";
    exit(0);
}
fwrite(STDERR, "comando inválido\n");
exit(1);
