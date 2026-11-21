#!/bin/bash
# Re-run the POS staging checks on a FRESH database, reusing the site built by pos-staging.sh.
# Runs: 4-phase operations workflow, then the Codex counter test(s), then extra eval files given as args.
# Usage: tools/qa/pos-fresh.sh [extra-test.php ...]
set -uo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"; L=/home/claude/wplocal; P=$L/pos
DBPW=$(cat /tmp/claude-0/bos-dbpw)
docker exec bos-db mariadb -uroot -p"$DBPW" -e "DROP DATABASE IF EXISTS wppos; CREATE DATABASE wppos" || exit 1
rm -rf "$P/wp-content/plugins/sfm4u-wholesale"; cp -r "$REPO/wh/sfm4u-wholesale" "$P/wp-content/plugins/"
cp "$P/wp-content/plugins/sfm4u-wholesale/modules/inventory-bridge/src/LossAwareWpdb.php" "$P/wp-content/sfm4u-db/"
mv "$P/wp-content/db.php" /tmp/claude-0/db.php.hold
W="php -d memory_limit=1024M $L/wpcli.phar --allow-root --path=$P --quiet"
$W core install --url=http://127.0.0.1 --title=SFM4U-POS-Staging --admin_user=fixture_admin \
  --admin_password="$(python3 -c "import secrets;print(secrets.token_urlsafe(18))")" --admin_email=fixture@example.invalid --skip-email >/dev/null 2>&1
$W plugin activate woocommerce dokan-lite >/dev/null 2>&1
$W option update woocommerce_custom_orders_table_enabled yes; $W option update woocommerce_currency BDT
mv /tmp/claude-0/db.php.hold "$P/wp-content/db.php"
ln -sfn "$P" /wordpress; ln -sfn "$REPO/tests/codex" /tests; ln -sfn "$P/wp-content/plugins/sfm4u-wholesale/modules" /modules
mkdir -p /fixture-out && rm -f /fixture-out/*
cd /tmp/claude-0 && php -d memory_limit=1024M activate.php >/dev/null 2>&1
R=PASS
for step in init workflow reactivate resumed; do
  printf '%s\n' '<?php' "\$_SERVER['HTTP_HOST']='127.0.0.1';" "putenv('SFM4U_OPERATIONS_PHASE=$step');" "require '/tests/b2b-installed-operations.php';" > phase.php
  php -d memory_limit=1024M phase.php >/tmp/claude-0/phase-$step.log 2>&1 || { R=FAIL; echo "PHASE $step FAILED"; tail -3 /tmp/claude-0/phase-$step.log; }
done
echo "4-phase: $(grep -c '^PASS' /fixture-out/operations-workflow.txt) PASS, result $R"
WV="php -d memory_limit=1024M $L/wpcli.phar --allow-root --path=$P"
for t in /tests/counter-member-confirm.php "$@"; do
  out=$($WV eval "require '$t';" 2>&1); rc=$?
  echo "$(basename "$t"): exit $rc, PASS $(echo "$out" | grep -c '^PASS'), FAIL lines $(echo "$out" | grep -ciE '^FAIL|Fatal|Uncaught')"
  echo "$out" | grep -iE '^FAIL|Fatal|Uncaught' | head -3
done
