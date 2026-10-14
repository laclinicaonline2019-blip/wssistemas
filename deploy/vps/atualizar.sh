#!/usr/bin/env bash
# Instala ou atualiza o WS Facilit Saúde numa VPS a partir do pacote .zip (o mesmo da HostGator, já com vendor/).
#
#   sudo -u wsfacilitsaude deploy/vps/atualizar.sh /caminho/wsfacilitsaude-XXXX.zip     # instala/atualiza
#   sudo -u wsfacilitsaude deploy/vps/atualizar.sh --voltar                      # volta para a versão anterior
#
# Estrutura (docs/VPS.md):
#   /var/www/wsfacilitsaude/releases/<data>   uma pasta por versão
#   /var/www/wsfacilitsaude/shared/.env       configuração (nunca é sobrescrita)
#   /var/www/wsfacilitsaude/shared/storage    anexos, logs e backups (nunca são sobrescritos)
#   /var/www/wsfacilitsaude/current           link para a versão no ar
set -euo pipefail

BASE=${WSFACILITSAUDE_BASE:-${WSCLINIC_BASE:-${AIVEXA_BASE:-/var/www/wsfacilitsaude}}}
# Servidor instalado com um nome antigo (/var/www/wsclinic ou /var/www/aivexa): continua usando a pasta existente.
if [[ ! -d "$BASE" && -d /var/www/wsclinic ]]; then BASE=/var/www/wsclinic; fi
if [[ ! -d "$BASE" && -d /var/www/aivexa ]]; then BASE=/var/www/aivexa; fi
KEEP=${WSFACILITSAUDE_KEEP_RELEASES:-${WSCLINIC_KEEP_RELEASES:-${AIVEXA_KEEP_RELEASES:-5}}}
PHP=${PHP_BIN:-/usr/bin/php}

reload_services() {
    # O usuário wsfacilitsaude precisa de permissão sudo só para estes dois comandos (ver docs/VPS.md, passo 9).
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
    # A versão no ar pode ter um nome antigo (comandos wsclinic:backup ou aivexa:backup).
    "$PHP" "$BASE/current/artisan" wsfacilitsaude:backup 2>/dev/null || "$PHP" "$BASE/current/artisan" wsclinic:backup 2>/dev/null || "$PHP" "$BASE/current/artisan" aivexa:backup
fi

cd "$REL"
echo "→ Migrations"
"$PHP" artisan migrate --force
"$PHP" artisan wsfacilitsaude:permissions:sync --roles || true
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
"$PHP" "$BASE/current/artisan" wsfacilitsaude:preflight || echo "ATENÇÃO: a verificação apontou erros — veja acima e corrija (ou use --voltar)."
echo "Pronto: $(basename "$REL") no ar."
