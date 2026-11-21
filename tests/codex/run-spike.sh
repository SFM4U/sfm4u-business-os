#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
fixture() {
    docker compose -p sfmwh-local -f .fixture/compose.yaml exec -T wp "$@"
}
fixture php /usr/local/bin/wp --allow-root plugin activate sfm4u-wholesale
fixture php /usr/local/bin/wp --allow-root eval-file /tests/spike.php
docker run --rm --network none -v "$PWD:/project:ro" noakhali_dev2-wordpress_current:latest php /project/tests/unit.php
