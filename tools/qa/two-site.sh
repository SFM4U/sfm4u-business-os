#!/bin/bash
# L-03: disposable multi-site run for CardLink / pairing / Black StockFeed / BOS master-shop tests (synthetic).
# Sites: shop = /home/claude/wplocal/site (http://127.0.0.1:8080), master = .../master (:8899), receiver/AlgiBazar = .../recv (:8894).
# Needs the MariaDB container from pos-staging.sh (bos-db) and wpcli.phar in /home/claude/wplocal.
set -uo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"; L=/home/claude/wplocal; T="$REPO/wh/tests-local"
DBPW=$(cat /tmp/claude-0/bos-dbpw)
mk() { # name port db
  local d=$L/$1; rm -rf "$d"; cp -r $L/site "$d"; rm -f "$d/wp-config.php"
  docker exec bos-db mariadb -uroot -p"$DBPW" -e "DROP DATABASE IF EXISTS $3; CREATE DATABASE $3"
  local W="php $L/wpcli.phar --allow-root --path=$d --quiet"
  $W config create --dbname=$3 --dbuser=root --dbpass="$DBPW" --dbhost=127.0.0.1:3306 --skip-check --force --extra-php <<'PHP'
define('WP_ENVIRONMENT_TYPE', 'local');
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('WP_ACCESSIBLE_HOSTS', '127.0.0.1,localhost');
PHP
  $W core install --url=http://127.0.0.1:$2 --title="QA $1 (synthetic)" --admin_user=fixture_admin \
    --admin_password="$(python3 -c "import secrets;print(secrets.token_urlsafe(18))")" --admin_email=fixture@example.invalid --skip-email 2>/dev/null
  rm -rf "$d/wp-content/plugins/sfm4u-wholesale"; cp -r "$REPO/wh/sfm4u-wholesale" "$d/wp-content/plugins/"
  $W plugin activate woocommerce dokan-lite sfm4u-wholesale 2>/dev/null
  $W option update woocommerce_currency BDT; $W option update woocommerce_custom_orders_table_enabled yes
}
serve() { pkill -f "php -S 127.0.0.1:$1" 2>/dev/null; (cd "$2" && PHP_CLI_SERVER_WORKERS=4 setsid nohup php -S 127.0.0.1:$1 </dev/null >/tmp/claude-0/serve-$1.log 2>&1 &); }
rm -rf "$L/site/wp-content/plugins/sfm4u-wholesale"; cp -r "$REPO/wh/sfm4u-wholesale" "$L/site/wp-content/plugins/"
[ "${SKIP_BUILD:-0}" = 1 ] || { mk master 8899 wpmaster; mk recv 8894 wprecv; }
serve 8080 $L/site; serve 8899 $L/master; serve 8894 $L/recv; sleep 2
WS="php $L/wpcli.phar --allow-root --path=$L/site"; WM="php $L/wpcli.phar --allow-root --path=$L/master"; WR="php $L/wpcli.phar --allow-root --path=$L/recv"
run() { local who=$1 f=$2; local W; case $who in shop) W=$WS;; master) W=$WM;; recv) W=$WR;; esac
  printf "%-7s %-22s " "$who" "$f"; timeout 300 $W eval-file "$T/$f.php" 2>&1 | grep -vE "sendmail|^Notice|^Deprecated|^PHP (Notice|Deprecated)" | tail -2 | tr '\n' ' '; echo; }
rm -f /tmp/link.json /tmp/pair.json /tmp/pair2.json /tmp/stock_pair.json /tmp/stock_pair2.json /tmp/stock_vendor.txt /tmp/bos.json
echo "== CardLink"; run master link-master-setup; run shop link-shop-test; run shop link-central-test
echo "== Pairing"; run shop pair-shop-start; run master pair-master-approve; run shop pair-shop-finish
echo "== Black StockFeed"; run shop stock-send-start; run recv stock-recv-setup; run recv stock-recv-approve; run shop stock-send-test; run recv stock-recv-check
echo "== BOS master/shop"; run recv bos-master-setup; run shop bos-shop-test; run recv bos-master-check
