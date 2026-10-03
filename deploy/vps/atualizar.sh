#!/usr/bin/env bash
# Instala ou atualiza o AivexaClínica numa VPS a partir do pacote .zip (o mesmo da HostGator, já com vendor/).
#
#   sudo -u aivexa deploy/vps/atualizar.sh /caminho/aivexa-XXXX.zip     # instala/atualiza
#   sudo -u aivexa deploy/vps/atualizar.sh --voltar                      # volta para a versão anterior
#
# Estrutura (docs/VPS.md):
#   /var/www/aivexa/releases/<data>   uma pasta por versão
#   /var/www/aivexa/shared/.env       configuração (nunca é sobrescrita)
#   /var/www/aivexa/shared/storage    anexos, logs e backups (nunca são sobrescritos)
#   /var/www/aivexa/current           link para a versão no ar
set -euo pipefail

BASE=${AIVEXA_BASE:-/var/www/aivexa}
KEEP=${AIVEXA_KEEP_RELEASES:-5}
PHP=${PHP_BIN:-/usr/bin/php}

reload_services() {
    # O usuário aivexa precisa de permissão sudo só para estes dois comandos (ver docs/VPS.md, passo 9).
    sudo -n /usr/bin/systemctl reload php8.3-fpm || echo "AVISO: recarregue o PHP-FPM manualmente (systemctl reload php8.3-fpm)."
    "$PHP" "$BASE/current/artisan" queue:restart || true
}

if [[ "${1:-}" == "--voltar" ]]; then
    cur=$(readlink -f "$BASE/current")
    prev=$(ls -1d "$BASE"/releases/* | sort | grep -v "^$cur$" | tail -1)
    [[ -n "$prev" ]] || { echo "Não há versão anterior."; exit 1; }
    ln -sfn "$prev" "$BASE/current.tmp" && mv -Tf "$BASE/current.tmp" "$BASE/current"
    reload_services
    echo "Voltou para $(basename "$prev"). Se a versão nova alterou o banco, restaure o backup feito antes da atualização (docs/PRODUCAO.md, seção 6)."
    exit 0
fi

ZIP=${1:?"Uso: $0 pacote.zip  |  $0 --voltar"}
[[ -f "$ZIP" ]] || { echo "Arquivo não encontrado: $ZIP"; exit 1; }
[[ -f "$BASE/shared/.env" ]] || { echo "Crie $BASE/shared/.env antes (docs/VPS.md, passo 5)."; exit 1; }

REL="$BASE/releases/$(date +%Y%m%d-%H%M%S)"
mkdir -p "$REL" "$BASE/shared"
echo "→ Extraindo em $REL"
unzip -q "$ZIP" -d "$REL"

# storage/ e .env compartilhados entre versões
if [[ ! -d "$BASE/shared/storage" ]]; then
    cp -a "$REL/storage" "$BASE/shared/storage"
fi
rm -rf "$REL/storage"
ln -s "$BASE/shared/storage" "$REL/storage"
ln -s "$BASE/shared/.env" "$REL/.env"
mkdir -p "$REL/bootstrap/cache"

# Backup antes de mexer no banco (só se já houver uma versão no ar).
if [[ -L "$BASE/current" ]]; then
    echo "→ Backup do banco antes da atualização"
    "$PHP" "$BASE/current/artisan" aivexa:backup
fi

cd "$REL"
echo "→ Migrations"
"$PHP" artisan migrate --force
"$PHP" artisan aivexa:permissions:sync --roles || true
echo "→ Caches"
"$PHP" artisan optimize:clear >/dev/null
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache

echo "→ Publicando a nova versão"
ln -sfn "$REL" "$BASE/current.tmp" && mv -Tf "$BASE/current.tmp" "$BASE/current"
reload_services

# Mantém só as últimas versões
ls -1d "$BASE"/releases/* | sort | head -n -"$KEEP" | while read -r old; do
    [[ "$old" == "$(readlink -f "$BASE/current")" ]] || rm -rf "$old"
done

echo "→ Verificação de prontidão"
"$PHP" "$BASE/current/artisan" aivexa:preflight || echo "ATENÇÃO: a verificação apontou erros — veja acima e corrija (ou use --voltar)."
echo "Pronto: $(basename "$REL") no ar."
