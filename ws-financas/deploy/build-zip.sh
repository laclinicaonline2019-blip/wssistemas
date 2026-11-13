#!/usr/bin/env bash
# Gera o pacote de instalação E de atualização pelo painel — dist/ws-financas-<versão>.zip
#  - arquivos na RAIZ do ZIP (extraia direto dentro da pasta do sistema no cPanel);
#  - inclui vendor/ (a hospedagem compartilhada não roda composer);
#  - nunca inclui .env, dados de storage, testes ou caches;
#  - wsf-update.json (manifesto) + CHECKSUMS.sha256 (SHA-256 de cada arquivo);
#  - assinatura Ed25519 opcional (WSF_UPDATE_SIGNING_KEY no ambiente de build).
# Uso: deploy/build-zip.sh [versão] [versão-mínima-de-origem]
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION="${1:-$(cat VERSION)}"
MIN_FROM="${2:-1.0.0}"
OUT="dist/build-$VERSION"
ZIP="dist/ws-financas-$VERSION.zip"
rm -rf "$OUT" "$ZIP"
mkdir -p "$OUT"

tar --exclude='./vendor' --exclude='./dist' --exclude='./node_modules' --exclude='./.env' --exclude='./.env.testing' --exclude='./database/*.sqlite' \
    --exclude='./storage/logs/*.log' --exclude='./storage/app/backups/*' --exclude='./storage/app/updates/*' --exclude='./storage/app/auditoria' --exclude='./storage/app/instalador' \
    --exclude='./storage/app/installed' --exclude='./storage/framework/views/*.php' --exclude='./storage/framework/sessions/*' \
    --exclude='./storage/framework/cache/data/*' --exclude='./bootstrap/cache/*.php' --exclude='./tests' --exclude='./.phpunit.cache' \
    --exclude='./phpunit.xml' --exclude='./.phpunit.result.cache' -cf - . | tar -xf - -C "$OUT"
for keep in storage/app/backups storage/app/updates storage/app/auditoria storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs; do
  mkdir -p "$OUT/$keep"; printf '*\n!.gitignore\n' > "$OUT/$keep/.gitignore"
done

# dependências de produção (sem pacotes de desenvolvimento)
( cd "$OUT" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts >/dev/null 2>&1 \
  && composer dump-autoload --no-dev --optimize --no-scripts >/dev/null 2>&1 )
find "$OUT/vendor" -name .git -type d -prune -exec rm -rf {} +
find "$OUT/vendor" -mindepth 3 -maxdepth 3 -type d \( -name tests -o -name Tests \) -prune -exec rm -rf {} +
printf '%s\n' "# Pasta interna: nunca servida pela web" "<IfModule mod_authz_core.c>" "    Require all denied" "</IfModule>" "<IfModule !mod_authz_core.c>" "    Order deny,allow" "    Deny from all" "</IfModule>" > "$OUT/vendor/.htaccess"
echo "$VERSION" > "$OUT/VERSION"

# lista de verificação e manifesto
( cd "$OUT" && find . -type f ! -name CHECKSUMS.sha256 ! -name wsf-update.json ! -name wsf-update.sig -print0 | sed -z 's#^\./##' | sort -z | xargs -0 sha256sum > CHECKSUMS.sha256 )
FILES=$(wc -l < "$OUT/CHECKSUMS.sha256")
SUMS=$(sha256sum "$OUT/CHECKSUMS.sha256" | cut -d' ' -f1)
cat > "$OUT/wsf-update.json" <<JSON
{
    "app": "ws-financas",
    "version": "$VERSION",
    "min_from": "$MIN_FROM",
    "php_min": "8.3.0",
    "built_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
    "files": $FILES,
    "checksums_sha256": "$SUMS",
    "notes": "${WSF_RELEASE_NOTES:-}"
}
JSON
if [ -n "${WSF_UPDATE_SIGNING_KEY:-}" ]; then php deploy/assinar-pacote.php assinar "$OUT/wsf-update.json" >/dev/null; echo "pacote assinado"; fi

( cd "$OUT" && zip -qr -X "../ws-financas-$VERSION.zip" . )
rm -rf "$OUT"
ls -lh "$ZIP"
sha256sum "$ZIP"
