#!/bin/bash
# Disposable local POS staging (synthetic data only). Mirrors wh/pos-staging-compose.yml without Docker-in-Docker.
# Needs: php 8.3 (mysqli, intl, gd, zip), docker (for MariaDB), git, curl.
# Usage: tools/qa/pos-staging.sh            -> builds site + runs the 4-phase operations workflow
# Result lines: /fixture-out/operations-workflow.txt
set -euo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
L=/home/claude/wplocal; P=$L/pos
mkdir -p "$L" /tmp/claude-0
docker rm -f bos-db >/dev/null 2>&1 || true
DBPW=$(python3 -c "import secrets;print(secrets.token_urlsafe(18))"); umask 077; echo "$DBPW" > /tmp/claude-0/bos-dbpw; umask 022
docker run -d --name bos-db -e MARIADB_ROOT_PASSWORD="$DBPW" -e MARIADB_DATABASE=wppos -p 127.0.0.1:3306:3306 mariadb:11.8 >/dev/null
cd "$L"
[ -f wpcli.phar ] || curl -sSL -o wpcli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
[ -f woocommerce.zip ] || curl -sSL -o woocommerce.zip https://github.com/woocommerce/woocommerce/releases/latest/download/woocommerce.zip
[ -f dokan-lite.zip ] || curl -sSL -o dokan-lite.zip https://github.com/getdokan/dokan/releases/latest/download/dokan-lite.zip
rm -rf "$P" && git clone -q -c advice.detachedHead=false --depth 1 --branch 7.1.5 https://github.com/WordPress/WordPress "$P" && rm -rf "$P/.git"
unzip -q woocommerce.zip -d "$P/wp-content/plugins/"; unzip -q dokan-lite.zip -d "$P/wp-content/plugins/"
cp -r "$REPO/wh/sfm4u-wholesale" "$P/wp-content/plugins/"
until docker exec bos-db mariadb-admin ping -p"$DBPW" >/dev/null 2>&1; do sleep 2; done
W="php -d memory_limit=1024M wpcli.phar --allow-root --path=$P --quiet"
$W config create --dbname=wppos --dbuser=root --dbpass="$DBPW" --dbhost=127.0.0.1:3306 --skip-check --force --extra-php <<'PHP'
define('SFM4U_B2B_ENABLED', true);
define('SFM4U_OPERATIONS_ENABLED', true);
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', '/tmp/claude-0/pos-debug.log');
define('WP_DEBUG_DISPLAY', false);
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('WP_ENVIRONMENT_TYPE', 'local');
PHP
$W core install --url=http://127.0.0.1 --title=SFM4U-POS-Staging --admin_user=fixture_admin \
  --admin_password="$(python3 -c "import secrets;print(secrets.token_urlsafe(18))")" --admin_email=fixture@example.invalid --skip-email
$W plugin activate woocommerce dokan-lite
$W option update woocommerce_custom_orders_table_enabled yes
$W option update woocommerce_currency BDT
mkdir -p "$P/wp-content/sfm4u-db" /fixture-out && rm -f /fixture-out/*
cp "$P/wp-content/plugins/sfm4u-wholesale/modules/inventory-bridge/src/LossAwareWpdb.php" "$P/wp-content/sfm4u-db/"
printf '%s\n' '<?php' "require_once WP_CONTENT_DIR . '/sfm4u-db/LossAwareWpdb.php';" '$wpdb = new \SFM4U\Inventory\LossAwareWpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);' > "$P/wp-content/db.php"
ln -sfn "$P" /wordpress; ln -sfn "$REPO/tests/codex" /tests; ln -sfn "$P/wp-content/plugins/sfm4u-wholesale/modules" /modules
cd /tmp/claude-0
printf '%s\n' '<?php' "\$_SERVER['HTTP_HOST']='127.0.0.1';" "require '/wordpress/wp-load.php';" "require_once ABSPATH . 'wp-admin/includes/plugin.php';" 'wp_set_current_user(1);' "\$r = activate_plugin('sfm4u-wholesale/sfm4u-wholesale.php');" "if (is_wp_error(\$r)) { throw new RuntimeException(\$r->get_error_message()); }" 'echo "activated\n";' > activate.php
php -d memory_limit=1024M activate.php
RESULT=PASS
for step in init workflow reactivate resumed; do
  printf '%s\n' '<?php' "\$_SERVER['HTTP_HOST']='127.0.0.1';" "putenv('SFM4U_OPERATIONS_PHASE=$step');" "require '/tests/b2b-installed-operations.php';" > phase.php
  echo "== phase $step"; php -d memory_limit=1024M phase.php || { RESULT=FAIL; echo "PHASE $step FAILED"; break; }
done
echo "operations checks PASS: $(grep -c '^PASS' /fixture-out/operations-workflow.txt)"
echo "SFM4U POS STAGING RESULT: $RESULT"
