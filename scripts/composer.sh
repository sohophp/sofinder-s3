#!/usr/bin/env sh
set -eu

package_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
composer_bin=${COMPOSER_BIN:-$(command -v composer || true)}
if [ "$composer_bin" = "" ]; then
    echo 'Composer was not found. Set COMPOSER_BIN to the Composer executable.' >&2
    exit 1
fi

exec "$package_dir/scripts/php-bin.sh" "$composer_bin" "$@"
