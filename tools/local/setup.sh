#!/usr/bin/env bash
# Entorno local de pruebas: MariaDB + WordPress + WooCommerce 11.2.1 (desde el repo oficial) + buckler-core.
# NO toca el sitio en producción. Uso: tools/local/setup.sh [--reset]
# Requisitos: php, composer, git, mariadb-server. Servidor: php -S localhost:8899 -t $SITE
set -euo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
BASE="${BK_LOCAL:-/tmp/claude-0/bk-local}"
SITE="$BASE/site"; WC="$BASE/wc"; DB="$BASE/mysql"
WC_TAG="11.2.1"

mkdir -p "$BASE"
# --- MariaDB ---------------------------------------------------------------
if [ ! -d "$DB/mysql" ]; then mariadb-install-db --datadir="$DB" --user=root >/dev/null; fi
if ! mariadb-admin --socket="$BASE/mysql.sock" ping >/dev/null 2>&1; then
  (mariadbd --datadir="$DB" --socket="$BASE/mysql.sock" --port=3307 --bind-address=127.0.0.1 --pid-file="$BASE/mysql.pid" --user=root >"$BASE/mysql.log" 2>&1 &)
  for i in $(seq 30); do mariadb-admin --socket="$BASE/mysql.sock" ping >/dev/null 2>&1 && break; sleep 1; done
fi
M="mariadb --socket=$BASE/mysql.sock -uroot"
[ "${1:-}" = "--reset" ] && $M -e "DROP DATABASE IF EXISTS bk"
$M -e "CREATE DATABASE IF NOT EXISTS bk CHARACTER SET utf8mb4; CREATE USER IF NOT EXISTS 'bk'@'localhost' IDENTIFIED BY 'bk'; GRANT ALL ON bk.* TO 'bk'@'localhost'; FLUSH PRIVILEGES;"

# --- WordPress -------------------------------------------------------------
if [ ! -f "$SITE/wp-settings.php" ]; then
  (cd "$BASE" && echo '{}' > composer.json && COMPOSER_ALLOW_SUPERUSER=1 composer require -q --no-interaction --no-plugins johnpbloch/wordpress-core)
  cp -r "$BASE/vendor/johnpbloch/wordpress-core" "$SITE"
fi
cat > "$SITE/wp-config.php" <<PHP
<?php
define('DB_NAME','bk'); define('DB_USER','bk'); define('DB_PASSWORD','bk'); define('DB_HOST','localhost:$BASE/mysql.sock');
define('DB_CHARSET','utf8mb4'); define('DB_COLLATE','');
\$table_prefix='wp_';
define('WP_DEBUG',true); define('WP_DEBUG_LOG',__DIR__.'/debug.log'); define('WP_DEBUG_DISPLAY',false);
define('AUTOMATIC_UPDATER_DISABLED',true); define('WP_HTTP_BLOCK_EXTERNAL',true); define('DISABLE_WP_CRON',true);
if(!defined('ABSPATH')) define('ABSPATH',__DIR__.'/');
require_once ABSPATH.'wp-settings.php';
PHP

# --- WooCommerce desde GitHub (wordpress.org está bloqueado en este entorno) --
if [ ! -f "$WC/plugins/woocommerce/vendor/autoload.php" ]; then
  git clone -q --depth 1 --branch "$WC_TAG" --filter=blob:none --sparse https://github.com/woocommerce/woocommerce.git "$WC"
  (cd "$WC" && git sparse-checkout set plugins/woocommerce packages/php)
  (cd "$WC/plugins/woocommerce" && COMPOSER_ALLOW_SUPERUSER=1 composer install -q --no-dev --no-interaction --no-scripts --prefer-source --ignore-platform-reqs && php bin/generate-feature-config.php >/dev/null \
    && mkdir -p assets/js && cp -r client/legacy/js/* assets/js/ \
    && find assets/js -name '*.js' ! -name '*.min.js' -exec sh -c 'cp "$1" "${1%.js}.min.js"' _ {} \;)
fi
# Registros vacíos para los scripts del admin React de Woo (no se compilan aquí).
(cd "$WC/plugins/woocommerce" && grep -rhoE "(register_script|enqueue_script|get_script_asset_filename)\( *'[a-z0-9-]+', *'[a-z0-9-]+'" src includes \
  | sed -E "s/.*\( *'([^']+)', *'([^']+)'/\1\/\2/" | sort -u | while read -r n; do d="assets/client/admin/$(dirname "$n")"; mkdir -p "$d"; \
  echo "<?php return array('dependencies'=>array(),'version'=>'local');" > "$d/$(basename "$n").asset.php"; touch "$d/$(basename "$n").js"; done)
ln -sfn "$WC/plugins/woocommerce" "$SITE/wp-content/plugins/woocommerce"
ln -sfn "$REPO/buckler-core" "$SITE/wp-content/plugins/buckler-core"
mkdir -p "$SITE/wp-content/mu-plugins"
cp "$REPO/tools/local/bk-local-fixtures.php" "$SITE/wp-content/mu-plugins/"

# --- Instalación + datos de ejemplo -----------------------------------------
php "$REPO/tools/local/install.php" "$SITE"
echo "Listo: php -S localhost:8899 -t $SITE"
