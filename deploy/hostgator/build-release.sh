#!/usr/bin/env bash
# Gera o pacote para envio via Gerenciador de Arquivos do cPanel (sem SSH/Composer no servidor).
# Uso: deploy/hostgator/build-release.sh  → dist/aivexa-<versão>.zip
# Empacota o último COMMIT (git archive HEAD) — faça commit das alterações antes.
set -euo pipefail
cd "$(dirname "$0")/../.."

VERSION=$(git describe --tags --always 2>/dev/null || date +%Y%m%d%H%M)
BUILD=$(mktemp -d)
OUT="dist/aivexa-${VERSION}.zip"

git archive HEAD | tar -x -C "$BUILD"
(cd "$BUILD" && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress)
# Remove metadados de VCS e testes de dependências (pacote menor para upload no cPanel).
find "$BUILD/vendor" -type d \( -name .git -o -name tests -o -name Tests -o -name docs \) -prune -exec rm -rf {} +
rm -rf "$BUILD"/tests "$BUILD"/.github "$BUILD"/docker "$BUILD"/Dockerfile "$BUILD"/docker-compose.yml "$BUILD"/.env.testing "$BUILD"/phpunit.xml
mkdir -p "$BUILD"/storage/{app,logs} "$BUILD"/storage/framework/{cache,sessions,views} "$BUILD"/bootstrap/cache

mkdir -p dist
(cd "$BUILD" && zip -qr - .) > "$OUT"
rm -rf "$BUILD"
echo "Pacote gerado: $OUT"
