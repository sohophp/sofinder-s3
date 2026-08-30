#!/usr/bin/env sh
set -eu

package_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
requested=$(sed -e 's/[[:space:]]//g' -e 's/#.*//' "$package_dir/.php-version" | sed -n '1p')
major=${requested%%.*}
minor=${requested#*.}
minor=${minor%%.*}

if [ "${PHP_BIN:-}" != "" ]; then
    if ! "$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' >/dev/null 2>&1; then
        echo 'PHP_BIN must point to PHP 8.1 or newer.' >&2
        exit 1
    fi
    exec "$PHP_BIN" "$@"
fi

for candidate in "php${major}${minor}" "php${major}.${minor}" php; do
    if command -v "$candidate" >/dev/null 2>&1 && "$candidate" -r "exit(PHP_MAJOR_VERSION === $major && PHP_MINOR_VERSION === $minor ? 0 : 1);" >/dev/null 2>&1; then
        exec "$candidate" "$@"
    fi
done

echo "This package requires the PHP $major.$minor development interpreter; set PHP_BIN for a compatibility run." >&2
exit 1
