#!/usr/bin/env bash
# Gera o pacote de instalação para hospedagem compartilhada (HostGator/cPanel) — dist/ws-financas-<versão>.zip
# Uso: deploy/build-zip.sh [versão]
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION="${1:-$(date +%Y.%m.%d)}"
OUT="dist/ws-financas-$VERSION"
rm -rf "$OUT" "dist/ws-financas-$VERSION.zip"
mkdir -p "$OUT"

# código (sem testes, segredos, bancos locais, logs ou backups)
tar --exclude='./vendor' --exclude='./dist' --exclude='./node_modules' --exclude='./.env' --exclude='./database/*.sqlite' \
    --exclude='./storage/logs/*.log' --exclude='./storage/app/backups/*.wsfbak' --exclude='./storage/app/installed' \
    --exclude='./storage/framework/views/*.php' --exclude='./storage/framework/sessions/*' --exclude='./storage/framework/cache/data/*' \
    --exclude='./bootstrap/cache/*.php' --exclude='./tests' --exclude='./.phpunit.cache' -cf - . | tar -xf - -C "$OUT"

# dependências de produção (sem pacotes de desenvolvimento)
( cd "$OUT" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts >/dev/null \
  && composer dump-autoload --no-dev --optimize --no-scripts >/dev/null \
  && php artisan package:discover >/dev/null 2>&1 || true )
# instalações "from source" trazem o histórico git de cada pacote — não vai para produção
find "$OUT/vendor" -name .git -type d -prune -exec rm -rf {} +
rm -f "$OUT/composer.lock.bak" "$OUT/.env.testing" "$OUT/phpunit.xml"
echo "$VERSION" > "$OUT/VERSION"

( cd dist && zip -qr "ws-financas-$VERSION.zip" "ws-financas-$VERSION" )
rm -rf "$OUT"
ls -lh "dist/ws-financas-$VERSION.zip"
