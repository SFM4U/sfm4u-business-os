# G0 fixture

The observed runtime was WordPress 7.1.4, WooCommerce 11.3.0, PHP 8.3.36 and MariaDB. The local profile uses the existing cached PHP image and a separate MariaDB volume, with port 18937 bound to loopback. No production data is used. This profile is a test record, not a supported-release matrix.

Create a private `.fixture` directory and copy `tests/fixture/compose.yaml` into it. Unpack the official WordPress 7.1.4 archive there as `wordpress/` and unpack WooCommerce 11.3.0 into its `wp-content/plugins/`. Save the official WP-CLI PHAR as `.fixture/wp-cli.phar`. The Compose file identifies the cached image and database image used in the Mac run. If those are unavailable, use an equivalent isolated PHP 8.3 image with mysqli and record the changed profile.

Start with `docker compose -p sfmwh-local -f .fixture/compose.yaml up -d --pull never`. In the `wp` service use `php /usr/local/bin/wp --allow-root` to create a config for the dedicated `sfmwh_test` database on host `db`, install WordPress at `http://127.0.0.1:18937` with a synthetic admin, and activate WooCommerce. Do not reuse this test database provisioning on a shared host. Use `./tests/run-spike.sh` and `docker compose -p sfmwh-local -f .fixture/compose.yaml exec -T wp sh /tests/g0-stock.sh`. Run `./tests/g0-print.sh` on the Mac after the price fixture. The last command uses Chrome and PDFKit.

## T05

```sh
docker run --rm --platform linux/amd64 --network none -v "$PWD:/project:ro" \
  noakhali_dev2-wordpress_current:latest php /project/tests/g0-valuation-unit.php
docker compose -p sfmwh-local -f .fixture/compose.yaml exec -T wp \
  php /usr/local/bin/wp --allow-root eval-file /tests/g0-valuation-woo.php
```
